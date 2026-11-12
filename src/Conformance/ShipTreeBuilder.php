<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

/**
 * Turns flat corporation asset rows into ship snapshots.
 *
 * Corp hangar items sit inside the corporation office item with flags
 * CorpSAG1..7; fitted modules, bays and cargo hang off each ship's item id.
 */
final class ShipTreeBuilder
{
    public const BAYS = ['FuelBay', 'FighterBay', 'FleetHangar', 'ShipMaintenanceBay'];

    private const SLOT_FLAGS = ['LoSlot' => 'low', 'MedSlot' => 'mid', 'HiSlot' => 'high', 'RigSlot' => 'rig'];

    private const BAY_FLAGS = [
        'SpecializedFuelBay' => 'FuelBay',
        'FuelBay' => 'FuelBay',
        'FighterBay' => 'FighterBay',
        'SpecializedFighterBay' => 'FighterBay',
        'FleetHangar' => 'FleetHangar',
        'ShipHangar' => 'ShipMaintenanceBay',
        'ShipMaintenanceBay' => 'ShipMaintenanceBay',
        'Cargo' => 'Cargo',
        'CargoBay' => 'Cargo',
        'DroneBay' => 'DroneBay',
    ];

    /**
     * EFT lists drones, fighters and T3 subsystems after the rigs together
     * with cargo, so those requirements count anything the ship carries in
     * these places.
     */
    private const CARRIED_BAYS = ['Cargo', 'DroneBay', 'FighterBay'];

    private const MAX_DEPTH = 3;

    /**
     * @param  int|list<int>  $officeIds  corporation office item ids at one location
     * @param  iterable<array{item_id: int, type_id: int, location_id: int, location_flag: string, is_singleton: bool|int, quantity: int, name?: ?string}>  $rows
     *         the offices' assets and everything below them
     * @return list<ShipSnapshot>
     */
    public static function fromOfficeAssets(int|array $officeIds, iterable $rows): array
    {
        $offices = array_fill_keys(array_map('intval', (array) $officeIds), true);
        $byParent = [];
        $ships = [];
        foreach ($rows as $row) {
            $byParent[(int) $row['location_id']][] = $row;
            if (isset($offices[(int) $row['location_id']])
                && str_starts_with((string) $row['location_flag'], 'CorpSAG')
                && (bool) $row['is_singleton']) {
                $ships[] = $row;
            }
        }

        $out = [];
        foreach ($ships as $ship) {
            $out[] = self::snapshot($ship, $byParent);
        }

        return $out;
    }

    /**
     * @param  array<int, list<array>>  $byParent
     */
    private static function snapshot(array $ship, array $byParent): ShipSnapshot
    {
        $shipId = (int) $ship['item_id'];
        $slots = array_fill_keys(EftParser::SLOT_SECTIONS, []);
        $bays = [];
        $cargo = [];
        $container = [];

        // breadth-first walk: [row, bay, depth, root item id of the bay entry]
        $queue = [];
        foreach ($byParent[$shipId] ?? [] as $child) {
            $flag = (string) $child['location_flag'];
            $section = self::slotSection($flag);
            if ($section !== null && (bool) $child['is_singleton']) {
                self::add($slots[$section], (int) $child['type_id'], self::qty($child));
            }
            if (str_starts_with($flag, 'SubSystemSlot')) {
                self::add($cargo, (int) $child['type_id'], self::qty($child));
            }
            $queue[] = [$child, self::BAY_FLAGS[$flag] ?? null, 1, (int) $child['item_id']];
        }

        for ($next = 0; $next < count($queue); $next++) {
            [$row, $bay, $depth, $rootId] = $queue[$next];
            $typeId = (int) $row['type_id'];
            if (in_array($bay, self::CARRIED_BAYS, true)) {
                self::add($cargo, $typeId, self::qty($row));
            }
            if ($bay !== null && $bay !== 'Cargo' && $bay !== 'DroneBay') {
                $bays[$bay] ??= [];
                self::add($bays[$bay], $typeId, self::qty($row));
            }
            if ($depth > 1 && ($bay === 'Cargo' || $bay === 'FleetHangar')) {
                self::add($container, $typeId, self::qty($row));
            }
            if ($bay !== null && $depth < self::MAX_DEPTH) {
                foreach ($byParent[(int) $row['item_id']] ?? [] as $child) {
                    $queue[] = [$child, $bay, $depth + 1, $rootId];
                }
            }
        }

        $division = null;
        if (preg_match('/^CorpSAG(\d)$/', (string) $ship['location_flag'], $m)) {
            $division = (int) $m[1];
        }

        return new ShipSnapshot(
            $shipId, (int) $ship['type_id'], $ship['name'] ?? null, $division,
            $slots, $cargo, $container, $bays,
        );
    }

    private static function slotSection(string $flag): ?string
    {
        foreach (self::SLOT_FLAGS as $prefix => $section) {
            if (str_starts_with($flag, $prefix)) {
                return $section;
            }
        }

        return null;
    }

    private static function qty(array $row): int
    {
        $qty = (int) ($row['quantity'] ?? 0);

        return $qty > 0 ? $qty : 1;
    }

    /** @param array<int, int> $bucket */
    private static function add(array &$bucket, int $typeId, int $qty): void
    {
        if ($typeId > 0) {
            $bucket[$typeId] = ($bucket[$typeId] ?? 0) + $qty;
        }
    }
}
