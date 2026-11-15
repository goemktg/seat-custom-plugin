<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

/**
 * Picks the doctrine fit closest to a ship and lists what differs.
 *
 * Fitted modules decide the closest fit; hold shortfalls only break ties, and
 * among perfect matches the fit with more hold requirements wins (fit variants
 * that share modules but differ by fuel or container loadout).
 */
final class FitMatcher
{
    /** @var \Closure(int, int): bool */
    private readonly \Closure $equivalent;

    /**
     * @param  (callable(int, int): bool)|null  $equivalent  true when two different type ids are interchangeable
     */
    public function __construct(?callable $equivalent = null)
    {
        $this->equivalent = $equivalent !== null
            ? \Closure::fromCallable($equivalent)
            : static fn (int $a, int $b): bool => false;
    }

    /**
     * @param  list<CompiledFit>  $candidates
     */
    public function best(ShipSnapshot $ship, array $candidates): ?MatchResult
    {
        $best = null;
        $bestKey = null;
        foreach ($candidates as $fit) {
            [$missing, $extra] = $this->slotDiff($ship->slots, $fit->slots);
            [$cargoMissing, $cargoLeft] = $this->holdShortfall($ship->cargo, $fit->cargo, 'cargo', true);
            [$containerMissing, $containerLeft] = $this->holdShortfall($ship->container, $fit->container, 'container', true);
            $bayMissing = [];
            foreach ($fit->bays as $bay => $required) {
                array_push($bayMissing, ...$this->holdShortfall($ship->bays[$bay] ?? [], $required, (string) $bay, false)[0]);
            }

            // cargo containers are part of cargo too, so what the fit's containers use there is
            // not spare; fleet hangar containers are not in cargo and are taken to supply first
            foreach ($ship->container as $typeId => $qty) {
                $used = $qty - ($containerLeft[$typeId] ?? 0) - ($ship->fleetContainer[$typeId] ?? 0);
                if ($used > 0 && isset($cargoLeft[$typeId])) {
                    $cargoLeft[$typeId] = max(0, $cargoLeft[$typeId] - $used);
                }
            }

            // a refit the fit carries in cargo but the ship has fitted is still on board
            [$cargoMissing, $extra, $refits] = $this->cover($cargoMissing, $extra, false);
            // and so is a fitted module sitting in cargo beyond what the fit carries there
            [$missing, , $stowed] = $this->cover($missing, self::lines($cargoLeft, 'cargo'), true);

            $moduleMissing = self::total($missing) + count($fit->unresolved);
            $moduleScore = $moduleMissing + self::total($extra);
            $score = $moduleScore + self::total($cargoMissing) + self::total($containerMissing) + self::total($bayMissing);

            $key = [$moduleScore, $score, $moduleMissing, -$fit->requirementCount(), $fit->order, $fit->id];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = new MatchResult($fit, $score === 0, $score, $missing, $extra, $cargoMissing, $containerMissing, $bayMissing, $refits, $stowed);
            }
        }

        return $best;
    }

    /**
     * @param  array<string, array<int, int>>  $actual
     * @param  array<string, array<int, int>>  $expected
     * @return array{0: list<array{section: string, typeId: int, count: int}>, 1: list<array{section: string, typeId: int, count: int}>}
     */
    private function slotDiff(array $actual, array $expected): array
    {
        $missing = [];
        $extra = [];
        foreach (EftParser::SLOT_SECTIONS as $section) {
            $have = $actual[$section] ?? [];
            $want = $expected[$section] ?? [];
            $short = [];
            $over = [];
            foreach (array_keys($have + $want) as $typeId) {
                $delta = ($want[$typeId] ?? 0) - ($have[$typeId] ?? 0);
                if ($delta > 0) {
                    $short[$typeId] = $delta;
                } elseif ($delta < 0) {
                    $over[$typeId] = -$delta;
                }
            }

            foreach ($short as $wantId => $_) {
                foreach ($over as $haveId => $__) {
                    if ($short[$wantId] === 0 || $over[$haveId] === 0 || ! ($this->equivalent)($wantId, $haveId)) {
                        continue;
                    }
                    $paired = min($short[$wantId], $over[$haveId]);
                    $short[$wantId] -= $paired;
                    $over[$haveId] -= $paired;
                }
            }

            foreach ($short as $typeId => $count) {
                if ($count > 0) {
                    $missing[] = ['section' => $section, 'typeId' => (int) $typeId, 'count' => $count];
                }
            }
            foreach ($over as $typeId => $count) {
                if ($count > 0) {
                    $extra[] = ['section' => $section, 'typeId' => (int) $typeId, 'count' => $count];
                }
            }
        }

        return [$missing, $extra];
    }

    /**
     * Minimum-quantity check: more than required is fine. Exact type ids are
     * consumed first so one stack never satisfies two requirements.
     *
     * Returns the shortfall lines and what is left of the hold after the requirements.
     *
     * @param  array<int, int>  $actual
     * @param  array<int, int>  $required
     * @return array{0: list<array{section: string, typeId: int, count: int}>, 1: array<int, int>}
     */
    private function holdShortfall(array $actual, array $required, string $section, bool $allowEquivalents): array
    {
        $available = $actual;
        $short = [];
        foreach ($required as $typeId => $need) {
            $used = min($need, $available[$typeId] ?? 0);
            $short[$typeId] = $need - $used;
            if ($used > 0) {
                $available[$typeId] -= $used;
            }
        }

        $out = [];
        foreach ($short as $typeId => $left) {
            if ($allowEquivalents) {
                foreach ($available as $haveId => $qty) {
                    if ($left <= 0) {
                        break;
                    }
                    if ($qty <= 0 || $haveId === $typeId || ! ($this->equivalent)($typeId, $haveId)) {
                        continue;
                    }
                    $used = min($left, $qty);
                    $left -= $used;
                    $available[$haveId] -= $used;
                }
            }
            if ($left > 0) {
                $out[] = ['section' => $section, 'typeId' => (int) $typeId, 'count' => $left];
            }
        }

        return [$out, $available];
    }

    /**
     * Fills needs from a pool of items found elsewhere on the ship, exact type ids
     * first, then equivalents. Each covered line keeps the pool item's type id and
     * the need's section when $needSection, else the pool line's section.
     *
     * @param  list<array{section: string, typeId: int, count: int}>  $needs
     * @param  list<array{section: string, typeId: int, count: int}>  $pool
     * @return array{0: list<array{section: string, typeId: int, count: int}>, 1: list<array{section: string, typeId: int, count: int}>, 2: list<array{section: string, typeId: int, count: int}>}
     */
    private function cover(array $needs, array $pool, bool $needSection): array
    {
        $covered = [];
        foreach ([false, true] as $equivalentPass) {
            foreach ($needs as $n => $need) {
                foreach ($pool as $p => $item) {
                    if ($needs[$n]['count'] === 0) {
                        break;
                    }
                    if ($item['count'] === 0) {
                        continue;
                    }
                    $fits = $equivalentPass
                        ? $item['typeId'] !== $need['typeId'] && ($this->equivalent)($need['typeId'], $item['typeId'])
                        : $item['typeId'] === $need['typeId'];
                    if (! $fits) {
                        continue;
                    }
                    $used = min($needs[$n]['count'], $item['count']);
                    $needs[$n]['count'] -= $used;
                    $pool[$p]['count'] -= $used;
                    $item['count'] -= $used;
                    $section = $needSection ? $need['section'] : $item['section'];
                    $key = $section . ':' . $item['typeId'];
                    $covered[$key] ??= ['section' => $section, 'typeId' => $item['typeId'], 'count' => 0];
                    $covered[$key]['count'] += $used;
                }
            }
        }

        $left = static fn (array $lines): array => array_values(array_filter($lines, static fn (array $l): bool => $l['count'] > 0));

        return [$left($needs), $left($pool), array_values($covered)];
    }

    /**
     * @param  array<int, int>  $quantities
     * @return list<array{section: string, typeId: int, count: int}>
     */
    private static function lines(array $quantities, string $section): array
    {
        $out = [];
        foreach ($quantities as $typeId => $count) {
            if ($count > 0) {
                $out[] = ['section' => $section, 'typeId' => (int) $typeId, 'count' => $count];
            }
        }

        return $out;
    }

    /** @param list<array{count: int}> $lines */
    private static function total(array $lines): int
    {
        return array_sum(array_column($lines, 'count'));
    }
}
