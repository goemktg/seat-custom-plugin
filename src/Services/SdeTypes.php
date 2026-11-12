<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Services;

use Illuminate\Support\Facades\DB;

/**
 * Item names, ship hulls and module equivalence from SeAT's SDE tables.
 */
class SdeTypes
{
    private const SHIP_CATEGORY = 6;

    /** techLevel, metaLevel, metaGroupID: differ between otherwise identical variants */
    private const IGNORED_ATTRIBUTES = [422, 633, 1692];

    /** Corpus X-Type / Core X-Type armor hardeners: same stats, different attribute rows */
    private const EXPLICIT_PAIRS = [[18933, 18973], [18937, 18975], [18941, 18977], [18945, 18979]];

    /**
     * @param  list<string>  $names
     * @return array<string, array{id: int, name: string}> keyed by lower-cased name
     */
    public function lookup(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names), static fn ($n) => $n !== '')));
        if ($names === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($names, 500) as $chunk) {
            $rows = DB::table('invTypes')
                ->whereIn('typeName', $chunk)
                ->orderByDesc('published')
                ->orderBy('typeID')
                ->get(['typeID', 'typeName']);
            foreach ($rows as $row) {
                $key = mb_strtolower((string) $row->typeName);
                $out[$key] ??= ['id' => (int) $row->typeID, 'name' => (string) $row->typeName];
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, string>
     */
    public function names(array $typeIds): array
    {
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
        if ($typeIds === []) {
            return [];
        }

        return DB::table('invTypes')->whereIn('typeID', $typeIds)
            ->pluck('typeName', 'typeID')
            ->mapWithKeys(static fn ($name, $id) => [(int) $id => (string) $name])
            ->all();
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, true> the ids that are ship hulls
     */
    public function shipTypeIds(array $typeIds): array
    {
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
        if ($typeIds === []) {
            return [];
        }

        return DB::table('invTypes')
            ->join('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
            ->whereIn('invTypes.typeID', $typeIds)
            ->where('invGroups.categoryID', self::SHIP_CATEGORY)
            ->pluck('invTypes.typeID')
            ->mapWithKeys(static fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Type ids are interchangeable when they share a group and every dogma
     * attribute except tech/meta level.
     *
     * @param  list<int>  $typeIds  every type id the comparison may meet
     * @return \Closure(int, int): bool
     */
    public function equivalence(array $typeIds): \Closure
    {
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
        $signatures = [];
        if ($typeIds !== []) {
            $groups = DB::table('invTypes')->whereIn('typeID', $typeIds)->pluck('groupID', 'typeID');
            $attributes = [];
            $rows = DB::table('dgmTypeAttributes')
                ->whereIn('typeID', $typeIds)
                ->whereNotIn('attributeID', self::IGNORED_ATTRIBUTES)
                ->orderBy('typeID')->orderBy('attributeID')
                ->get(['typeID', 'attributeID', 'valueInt', 'valueFloat']);
            foreach ($rows as $row) {
                $value = $row->valueFloat !== null ? round((float) $row->valueFloat, 10) : (float) $row->valueInt;
                $attributes[(int) $row->typeID][] = $row->attributeID . '=' . $value;
            }
            foreach ($groups as $typeId => $groupId) {
                // without dogma rows two types cannot be shown equal; never guess
                if (! empty($attributes[(int) $typeId])) {
                    $signatures[(int) $typeId] = $groupId . '|' . implode(',', $attributes[(int) $typeId]);
                }
            }
        }

        $pairs = [];
        foreach (self::EXPLICIT_PAIRS as [$a, $b]) {
            $pairs[$a . ':' . $b] = true;
            $pairs[$b . ':' . $a] = true;
        }

        return static function (int $a, int $b) use ($signatures, $pairs): bool {
            if ($a === $b || isset($pairs[$a . ':' . $b])) {
                return true;
            }

            return isset($signatures[$a], $signatures[$b]) && $signatures[$a] === $signatures[$b];
        };
    }
}
