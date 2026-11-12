<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

/**
 * A ship compared with its closest doctrine fit. Each diff line is
 * ['section' => slot section, bay key, 'cargo' or 'container', 'typeId' => int, 'count' => int].
 */
final class MatchResult
{
    /**
     * @param  list<array{section: string, typeId: int, count: int}>  $missing
     * @param  list<array{section: string, typeId: int, count: int}>  $extra
     * @param  list<array{section: string, typeId: int, count: int}>  $cargoMissing
     * @param  list<array{section: string, typeId: int, count: int}>  $containerMissing
     * @param  list<array{section: string, typeId: int, count: int}>  $bayMissing
     */
    public function __construct(
        public readonly CompiledFit $fit,
        public readonly bool $conformant,
        public readonly int $score,
        public readonly array $missing,
        public readonly array $extra,
        public readonly array $cargoMissing,
        public readonly array $containerMissing,
        public readonly array $bayMissing,
    ) {
    }

    public function missingCount(): int
    {
        return self::total($this->missing) + self::total($this->cargoMissing)
            + self::total($this->containerMissing) + self::total($this->bayMissing)
            + count($this->fit->unresolved);
    }

    public function extraCount(): int
    {
        return self::total($this->extra);
    }

    /** Distinct items to add: what an officer works through, unlike summed fuel units. */
    public function itemsToAdd(): int
    {
        return count($this->missing) + count($this->cargoMissing) + count($this->containerMissing)
            + count($this->bayMissing) + count($this->fit->unresolved);
    }

    public function itemsToRemove(): int
    {
        return count($this->extra);
    }

    /** @param list<array{count: int}> $lines */
    private static function total(array $lines): int
    {
        return array_sum(array_column($lines, 'count'));
    }
}
