<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

/**
 * The Hangar Check page's content for one staging: ships needing fixes,
 * ships with no fit for their hull, and per-fit target vs conformant counts.
 */
final class HangarReport
{
    /**
     * @param  list<array{ship: ShipSnapshot, match: MatchResult}>  $problems
     * @param  list<ShipSnapshot>  $unmatched
     * @param  list<array{fitId: int, name: string, target: int, match: int, needFix: int, short: int, fit: CompiledFit}>  $fits
     */
    private function __construct(
        public readonly array $problems,
        public readonly array $unmatched,
        public readonly array $fits,
        public readonly int $conformantCount,
    ) {
    }

    /**
     * @param  list<ShipSnapshot>  $ships
     * @param  list<CompiledFit>  $fits  ordered as they should be listed
     * @param  array<int, int>  $targets  fit id => target at this staging
     * @param  callable(int): bool  $isShip  whether a type id is a ship hull
     */
    public static function build(array $ships, array $fits, array $targets, FitMatcher $matcher, callable $isShip): self
    {
        $byHull = [];
        foreach ($fits as $fit) {
            $byHull[$fit->shipTypeId][] = $fit;
        }

        $matchCount = [];
        $needFixCount = [];
        $problems = [];
        $unmatched = [];
        $conformant = 0;
        foreach ($ships as $ship) {
            $candidates = $byHull[$ship->typeId] ?? [];
            if ($candidates === []) {
                if ($isShip($ship->typeId)) {
                    $unmatched[] = $ship;
                }
                continue;
            }
            $match = $matcher->best($ship, $candidates);
            $fitId = $match->fit->id;
            if ($match->conformant) {
                $matchCount[$fitId] = ($matchCount[$fitId] ?? 0) + 1;
                $conformant++;
                continue;
            }
            $needFixCount[$fitId] = ($needFixCount[$fitId] ?? 0) + 1;
            $problems[] = ['ship' => $ship, 'match' => $match];
        }

        usort($problems, static fn (array $a, array $b): int => [$a['match']->fit->order, (string) $a['ship']->name, $a['ship']->itemId]
            <=> [$b['match']->fit->order, (string) $b['ship']->name, $b['ship']->itemId]);
        usort($unmatched, static fn (ShipSnapshot $a, ShipSnapshot $b): int => [(string) $a->name, $a->itemId] <=> [(string) $b->name, $b->itemId]);

        $rows = [];
        foreach ($fits as $fit) {
            $target = max(0, (int) ($targets[$fit->id] ?? 0));
            $match = $matchCount[$fit->id] ?? 0;
            $rows[] = [
                'fitId' => $fit->id,
                'name' => $fit->name,
                'target' => $target,
                'match' => $match,
                'needFix' => $needFixCount[$fit->id] ?? 0,
                'short' => max(0, $target - $match),
                'fit' => $fit,
            ];
        }

        return new self($problems, $unmatched, $rows, $conformant);
    }

    public function shortFitCount(): int
    {
        return count(array_filter($this->fits, static fn (array $row): bool => $row['short'] > 0));
    }
}
