<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

/**
 * What one assembled ship in a corp hangar actually carries.
 */
final class ShipSnapshot
{
    /**
     * @param  array<string, array<int, int>>  $slots  section => type id => count
     * @param  array<int, int>  $cargo  everything under the cargo hold, containers included
     * @param  array<int, int>  $container  items inside containers in cargo or fleet hangar
     * @param  array<string, array<int, int>>  $bays  bay => type id => quantity
     */
    public function __construct(
        public readonly int $itemId,
        public readonly int $typeId,
        public readonly ?string $name,
        public readonly ?int $division,
        public readonly array $slots,
        public readonly array $cargo,
        public readonly array $container,
        public readonly array $bays,
    ) {
    }
}
