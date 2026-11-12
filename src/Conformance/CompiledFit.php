<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

/**
 * A doctrine fit with every requirement expressed as type id counts.
 */
final class CompiledFit
{
    /**
     * @param  array<string, array<int, int>>  $slots  section => type id => count
     * @param  array<int, int>  $cargo  type id => minimum quantity
     * @param  array<int, int>  $container  type id => minimum quantity inside containers
     * @param  array<string, array<int, int>>  $bays  bay => type id => minimum quantity
     * @param  array<int, string>  $names  type id => name, as written in the fit
     * @param  list<string>  $unresolved  fit lines that matched no item
     */
    public function __construct(
        public readonly int $id,
        public readonly string $doctrine,
        public readonly string $name,
        public readonly int $shipTypeId,
        public readonly int $order,
        public readonly array $slots,
        public readonly array $cargo,
        public readonly array $container,
        public readonly array $bays,
        public readonly array $names,
        public readonly array $unresolved,
    ) {
    }

    /** Total of hold requirements; breaks ties between fits with identical modules. */
    public function requirementCount(): int
    {
        $total = array_sum($this->cargo) + array_sum($this->container);
        foreach ($this->bays as $types) {
            $total += array_sum($types);
        }

        return $total;
    }
}
