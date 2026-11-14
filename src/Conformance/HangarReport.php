<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

/**
 * The Hangar Check page's content for one staging: ships needing fixes,
 * ships not named after any fit, and per-fit target vs ready counts.
 *
 * A ship is checked only against fits whose name is exactly the ship's
 * in-game name (surrounding whitespace aside) and whose hull is the ship's.
 */
final class HangarReport
{
    /**
     * @param  list<array{ship: ShipSnapshot, match: MatchResult}>  $problems  in fit order, then ship name
     * @param  list<ShipSnapshot>  $unmatched  ships with no fit of their name and hull; hinted ones first
     * @param  array<int, string>  $suggestions  unmatched ship item id => exact name of a same-hull fit
     *         that differs only in case or spacing
     * @param  list<array{fitId: int, name: string, target: int, named: int, match: int, needFix: int, short: int, missing: int, fit: CompiledFit}>  $fits
     */
    private function __construct(
        public readonly array $problems,
        public readonly array $unmatched,
        public readonly array $suggestions,
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
        $byName = [];
        $byLooseName = [];
        foreach ($fits as $fit) {
            $byName[self::nameKey($fit->name)][$fit->shipTypeId][] = $fit;
            $byLooseName[self::looseKey($fit->name)][$fit->shipTypeId] ??= self::nameKey($fit->name);
        }

        $matchCount = [];
        $needFixCount = [];
        $problems = [];
        $unmatched = [];
        $suggestions = [];
        $conformant = 0;
        foreach ($ships as $ship) {
            if (! $isShip($ship->typeId)) {
                continue;
            }
            $key = self::nameKey($ship->name);
            $candidates = $key === '' ? [] : ($byName[$key][$ship->typeId] ?? []);
            if ($candidates === []) {
                $unmatched[] = $ship;
                $hint = $key === '' ? null : ($byLooseName[self::looseKey($key)][$ship->typeId] ?? null);
                if ($hint !== null) {
                    $suggestions[$ship->itemId] = $hint;
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

        // strcmp, not <=>: PHP compares two numeric strings as numbers, which is not a total order
        usort($problems, static fn (array $a, array $b): int => ($a['match']->fit->order <=> $b['match']->fit->order)
            ?: strcmp((string) $a['ship']->name, (string) $b['ship']->name)
            ?: $a['ship']->itemId <=> $b['ship']->itemId);
        usort($unmatched, static fn (ShipSnapshot $a, ShipSnapshot $b): int => (isset($suggestions[$b->itemId]) <=> isset($suggestions[$a->itemId]))
            ?: strcmp((string) $a->name, (string) $b->name)
            ?: $a->itemId <=> $b->itemId);

        $rows = [];
        foreach ($fits as $fit) {
            $target = max(0, (int) ($targets[$fit->id] ?? 0));
            $match = $matchCount[$fit->id] ?? 0;
            $needFix = $needFixCount[$fit->id] ?? 0;
            $rows[] = [
                'fitId' => $fit->id,
                'name' => $fit->name,
                'target' => $target,
                'named' => $match + $needFix,
                'match' => $match,
                'needFix' => $needFix,
                'short' => max(0, $target - $match),
                // ships still to buy, or to rename after this fit
                'missing' => max(0, $target - $match - $needFix),
                'fit' => $fit,
            ];
        }

        return new self($problems, $unmatched, $suggestions, $rows, $conformant);
    }

    public function shortFitCount(): int
    {
        return count(array_filter($this->fits, static fn (array $row): bool => $row['short'] > 0));
    }

    /**
     * Problems split per fit, in fit order. Ships of one fit share its name,
     * so the page heads each group with the fit instead of repeating it.
     *
     * @return list<array{fit: CompiledFit, named: int, problems: list<array{ship: ShipSnapshot, match: MatchResult}>}>
     */
    public function problemGroups(): array
    {
        $named = array_column($this->fits, 'named', 'fitId');
        $groups = [];
        foreach ($this->problems as $problem) {
            $fit = $problem['match']->fit;
            $groups[$fit->id] ??= ['fit' => $fit, 'named' => $named[$fit->id] ?? 0, 'problems' => []];
            $groups[$fit->id]['problems'][] = $problem;
        }

        return array_values($groups);
    }

    /**
     * Ships and fits pair up by exact name, case included; only surrounding
     * whitespace (Unicode spaces included) is ignored. An empty key never matches.
     */
    public static function nameKey(?string $name): string
    {
        // the lookbehind lets the trailing alternative start only at the head of a
        // whitespace run, so a long inner run is not rescanned from every position
        // (quadratic without PCRE JIT; EFT header names reach here uncapped)
        return (string) preg_replace('/^[\s\p{Z}]+|(?<![\s\p{Z}])[\s\p{Z}]+$/u', '', (string) $name);
    }

    /** Case- and spacing-insensitive key, used only to suggest the exact name. */
    private static function looseKey(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{Z}]+/u', ' ', self::nameKey($name)));
    }
}
