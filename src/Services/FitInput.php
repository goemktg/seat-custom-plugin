<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Services;

use Goemktg\Seat\SeatCustomPlugin\Conformance\EftParser;
use Goemktg\Seat\SeatCustomPlugin\Conformance\FitCompiler;
use Goemktg\Seat\SeatCustomPlugin\Conformance\QuantityLines;
use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineFit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Validates the fit form and turns it into stored attributes. Every item
 * name must resolve, so a saved fit never carries unknown lines.
 */
class FitInput
{
    /** form field => stored bay key */
    public const BAY_FIELDS = [
        'fuel_bay' => 'FuelBay',
        'fleet_hangar' => 'FleetHangar',
        'fighter_bay' => 'FighterBay',
        'ship_maintenance_bay' => 'ShipMaintenanceBay',
    ];

    public function __construct(private readonly SdeTypes $sde)
    {
    }

    /**
     * @param  list<int>  $stagingIds  stagings of the fit's corporation
     * @return array{attributes: array<string, mixed>, targets: array<int, int>}
     *
     * @throws ValidationException
     */
    public function validate(Request $request, int $corporationId, array $stagingIds, ?DoctrineFit $existing = null): array
    {
        $data = $request->validate([
            'doctrine' => ['required', 'string', 'max:100'],
            'fit_name' => ['nullable', 'string', 'max:100'],
            'eft' => ['required', 'string', 'max:20000'],
            'fuel_bay' => ['nullable', 'string', 'max:5000'],
            'fleet_hangar' => ['nullable', 'string', 'max:5000'],
            'fighter_bay' => ['nullable', 'string', 'max:5000'],
            'ship_maintenance_bay' => ['nullable', 'string', 'max:5000'],
            'container' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'targets' => ['nullable', 'array'],
            'targets.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'version' => ['nullable', 'string'],
        ]);

        if ($existing !== null) {
            self::assertCurrent($existing, $data['version'] ?? null);
        }

        $errors = [];
        $lookup = fn (array $names) => $this->sde->lookup($names);

        try {
            $parsed = EftParser::parse($data['eft']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['eft' => 'Paste the fit in EFT format, starting with a line like [Zirnitra, Fit name].']);
        }

        $hull = $lookup([$parsed->shipName])[mb_strtolower($parsed->shipName)] ?? null;
        if ($hull === null || $this->sde->shipTypeIds([$hull['id']]) === []) {
            $errors['eft'][] = sprintf('"%s" in the first line is not a ship name.', $parsed->shipName);
        }

        $bays = [];
        foreach (self::BAY_FIELDS as $field => $bay) {
            $resolved = QuantityLines::resolve(preg_split('/\r\n|\r|\n/', (string) ($data[$field] ?? '')) ?: [], $lookup);
            if ($resolved->unresolved !== []) {
                $errors[$field][] = 'Unknown items: ' . implode(', ', $resolved->unresolved) . '. Write one item per line as "Name 100" or "Name x100".';
            }
            if ($resolved->counts !== []) {
                $bays[$bay] = self::items($resolved->counts, $resolved->names);
            }
        }

        $containerResolved = QuantityLines::resolve(preg_split('/\r\n|\r|\n/', (string) ($data['container'] ?? '')) ?: [], $lookup);
        if ($containerResolved->unresolved !== []) {
            $errors['container'][] = 'Unknown items: ' . implode(', ', $containerResolved->unresolved) . '. Write one item per line as "Name 4" or "Name x4".';
        }

        if ($hull !== null) {
            $compiled = FitCompiler::compile(0, '', '', $hull['id'], 0, $data['eft'], [], [], $lookup);
            if ($compiled->unresolved !== []) {
                $errors['eft'][] = 'Unknown items in the EFT: ' . implode(', ', $compiled->unresolved) . '. Check the spelling against the in-game name.';
            }
        }

        $doctrine = trim($data['doctrine']);
        $fitName = trim((string) ($data['fit_name'] ?? '')) ?: ($parsed->fitName !== '' ? $parsed->fitName : $doctrine);
        if (mb_strlen($fitName) > 100) {
            $errors['fit_name'][] = 'The fit name from the EFT\'s first line is longer than 100 characters. Type a shorter fit name.';
        }
        $duplicate = DoctrineFit::where('corporation_id', $corporationId)
            ->where('doctrine', $doctrine)->where('fit_name', $fitName)
            ->when($existing !== null, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($duplicate) {
            $errors['fit_name'][] = sprintf('Doctrine "%s" already has a fit named "%s".', $doctrine, $fitName);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $targets = [];
        foreach ($data['targets'] ?? [] as $stagingId => $target) {
            if (in_array((int) $stagingId, $stagingIds, true)) {
                $targets[(int) $stagingId] = (int) $target;
            }
        }

        return [
            'attributes' => [
                'corporation_id' => $corporationId,
                'doctrine' => $doctrine,
                'fit_name' => $fitName,
                'ship_type_id' => $hull['id'],
                'eft' => $data['eft'],
                'bays' => $bays === [] ? null : $bays,
                'container' => $containerResolved->counts === [] ? null : self::items($containerResolved->counts, $containerResolved->names),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ],
            'targets' => $targets,
        ];
    }

    /**
     * @throws ValidationException when the fit changed since the form was opened
     */
    public static function assertCurrent(DoctrineFit $fit, ?string $version): void
    {
        if (! hash_equals($fit->version(), (string) $version)) {
            throw ValidationException::withMessages([
                'eft' => 'Someone else saved this fit while you were editing. Reload the page to see their version.',
            ]);
        }
    }

    /**
     * Stored item lists back to the "Name qty" lines the form shows.
     *
     * @param  list<array{name: string, qty: int}>|null  $items
     */
    public static function toLines(?array $items): string
    {
        return implode("\n", array_map(static fn (array $i) => $i['name'] . ' ' . $i['qty'], $items ?? []));
    }

    /**
     * @param  array<int, int>  $counts
     * @param  array<int, string>  $names
     * @return list<array{name: string, qty: int, typeId: int}>
     */
    private static function items(array $counts, array $names): array
    {
        $items = [];
        foreach ($counts as $typeId => $qty) {
            $items[] = ['name' => $names[$typeId] ?? (string) $typeId, 'qty' => $qty, 'typeId' => $typeId];
        }
        usort($items, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $items;
    }
}
