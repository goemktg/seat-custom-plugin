<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

final class ResolvedQuantities
{
    /**
     * @param  array<int, int>  $counts  type id => quantity
     * @param  array<int, string>  $names  type id => canonical name
     * @param  list<string>  $unresolved  lines that matched no item
     */
    public function __construct(
        public readonly array $counts,
        public readonly array $names,
        public readonly array $unresolved,
    ) {
    }
}
