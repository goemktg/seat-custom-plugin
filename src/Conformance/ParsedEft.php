<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

final class ParsedEft
{
    /**
     * @param  array<string, array<string, int>>  $slots  section => module name => count
     * @param  list<string>  $cargoLines  raw lines after the rig section
     */
    public function __construct(
        public readonly string $shipName,
        public readonly string $fitName,
        public readonly array $slots,
        public readonly array $cargoLines,
    ) {
    }

    /**
     * @return list<string>
     */
    public function slotModuleNames(): array
    {
        $names = [];
        foreach ($this->slots as $modules) {
            foreach (array_keys($modules) as $name) {
                $names[$name] = true;
            }
        }

        return array_map('strval', array_keys($names));
    }
}
