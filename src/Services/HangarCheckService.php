<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Services;

use Goemktg\Seat\SeatCustomPlugin\Conformance\CompiledFit;
use Goemktg\Seat\SeatCustomPlugin\Conformance\FitCompiler;
use Goemktg\Seat\SeatCustomPlugin\Conformance\FitMatcher;
use Goemktg\Seat\SeatCustomPlugin\Conformance\HangarReport;
use Goemktg\Seat\SeatCustomPlugin\Conformance\ShipTreeBuilder;
use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineFit;
use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineStaging;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Hangar Check report for one staging from SeAT's synced
 * corporation assets.
 */
class HangarCheckService
{
    /** ship -> module/bay item -> container -> container contents */
    private const ASSET_DEPTH = 4;

    /** bump when CompiledFit's shape changes */
    private const COMPILED_FORMAT = 1;

    public function __construct(private readonly SdeTypes $sde)
    {
    }

    /**
     * @return array{report: HangarReport, names: array<int, string>, syncedAt: ?string, hasOffice: bool}
     */
    public function report(DoctrineStaging $staging): array
    {
        $fits = DoctrineFit::where('corporation_id', $staging->corporation_id)
            ->orderBy('sort_order')->orderBy('doctrine')->orderBy('fit_name')
            ->get();
        $compiled = $this->compileAll($fits);

        $officeIds = DB::table('corporation_assets')
            ->where('corporation_id', $staging->corporation_id)
            ->where('location_id', $staging->location_id)
            ->where('location_flag', 'OfficeFolder')
            ->pluck('item_id')->map(static fn ($id) => (int) $id)->all();

        [$rows, $syncedAt] = $this->assetRows($staging->corporation_id, $officeIds);
        $ships = ShipTreeBuilder::fromOfficeAssets($officeIds, $rows);

        // only what sits in or on the ships, plus the fits, needs names and equivalence
        $typeIds = [];
        foreach ($ships as $ship) {
            $typeIds[] = $ship->typeId;
            foreach ($ship->slots as $types) {
                array_push($typeIds, ...array_keys($types));
            }
            array_push($typeIds, ...array_keys($ship->cargo), ...array_keys($ship->container));
            foreach ($ship->bays as $types) {
                array_push($typeIds, ...array_keys($types));
            }
        }
        foreach ($compiled as $fit) {
            foreach ($fit->slots as $types) {
                array_push($typeIds, ...array_keys($types));
            }
            array_push($typeIds, ...array_keys($fit->cargo), ...array_keys($fit->container));
        }
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));

        $hulls = $this->sde->shipTypeIds(array_map(static fn ($s) => $s->typeId, $ships));
        $report = HangarReport::build(
            $ships,
            $compiled,
            DoctrineFit::targetsAtStaging($staging->id),
            new FitMatcher($this->sde->equivalence($typeIds)),
            static fn (int $typeId): bool => isset($hulls[$typeId]),
        );

        $names = $this->sde->names($typeIds);
        foreach ($compiled as $fit) {
            $names = $fit->names + $names;
        }

        return ['report' => $report, 'names' => $names, 'syncedAt' => $syncedAt, 'hasOffice' => $officeIds !== []];
    }

    /**
     * Compiled fits are cached per fit version: editing a fit changes
     * updated_at and so the key.
     *
     * @param  Collection<int, DoctrineFit>  $fits
     * @return list<CompiledFit>
     */
    public function compileAll(Collection $fits): array
    {
        $out = [];
        foreach ($fits->values() as $order => $fit) {
            // content hash, not updated_at: two saves in one second must not share a key;
            // the format version retires entries cached by older plugin releases
            $key = sprintf(
                'custom-plugin:doctrine-fit:v%d:%d:%d:%s',
                self::COMPILED_FORMAT, $fit->id, $order,
                sha1(json_encode([$fit->doctrine, $fit->fit_name, $fit->ship_type_id, $fit->eft, $fit->bays, $fit->container], JSON_THROW_ON_ERROR)),
            );
            $out[] = Cache::remember($key, now()->addDay(), fn () => FitCompiler::compile(
                id: $fit->id,
                doctrine: $fit->doctrine,
                name: $fit->fit_name,
                shipTypeId: $fit->ship_type_id,
                order: $order,
                eft: $fit->eft,
                bays: $fit->bays ?? [],
                container: $fit->container ?? [],
                lookup: fn (array $names) => $this->sde->lookup($names),
            ));
        }

        return $out;
    }

    /**
     * @param  list<int>  $officeIds
     * @return array{0: list<array>, 1: ?string} rows and the newest asset update time
     */
    private function assetRows(int $corporationId, array $officeIds): array
    {
        $rows = [];
        $syncedAt = null;
        $parents = $officeIds;
        for ($depth = 0; $depth < self::ASSET_DEPTH && $parents !== []; $depth++) {
            $level = [];
            foreach (array_chunk($parents, 1000) as $chunk) {
                $found = DB::table('corporation_assets')
                    ->where('corporation_id', $corporationId)
                    ->whereIn('location_id', $chunk)
                    ->get(['item_id', 'type_id', 'quantity', 'location_id', 'location_flag', 'is_singleton', 'name', 'updated_at']);
                foreach ($found as $row) {
                    $level[] = [
                        'item_id' => (int) $row->item_id,
                        'type_id' => (int) $row->type_id,
                        'quantity' => (int) $row->quantity,
                        'location_id' => (int) $row->location_id,
                        'location_flag' => (string) $row->location_flag,
                        'is_singleton' => (bool) $row->is_singleton,
                        'name' => $row->name,
                    ];
                    if ($row->updated_at !== null && ($syncedAt === null || $row->updated_at > $syncedAt)) {
                        $syncedAt = (string) $row->updated_at;
                    }
                }
            }
            // only descend into hangar items at the office level
            if ($depth === 0) {
                $level = array_values(array_filter($level, static fn (array $r) => str_starts_with($r['location_flag'], 'CorpSAG')));
            }
            foreach ($level as $row) {
                $rows[] = $row;
            }
            // stacks (ore, ammo) cannot hold anything; only assembled ships and containers can
            $parents = array_column(array_filter($level, static fn (array $r) => $r['is_singleton']), 'item_id');
        }

        return [$rows, $syncedAt];
    }
}
