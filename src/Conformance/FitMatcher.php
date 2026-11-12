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
            $cargoMissing = $this->holdShortfall($ship->cargo, $fit->cargo, 'cargo', true);
            $containerMissing = $this->holdShortfall($ship->container, $fit->container, 'container', true);
            $bayMissing = [];
            foreach ($fit->bays as $bay => $required) {
                array_push($bayMissing, ...$this->holdShortfall($ship->bays[$bay] ?? [], $required, (string) $bay, false));
            }

            $moduleMissing = self::total($missing) + count($fit->unresolved);
            $moduleScore = $moduleMissing + self::total($extra);
            $score = $moduleScore + self::total($cargoMissing) + self::total($containerMissing) + self::total($bayMissing);

            $key = [$moduleScore, $score, $moduleMissing, -$fit->requirementCount(), $fit->order, $fit->id];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = new MatchResult($fit, $score === 0, $score, $missing, $extra, $cargoMissing, $containerMissing, $bayMissing);
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
     * @param  array<int, int>  $actual
     * @param  array<int, int>  $required
     * @return list<array{section: string, typeId: int, count: int}>
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

        return $out;
    }

    /** @param list<array{count: int}> $lines */
    private static function total(array $lines): int
    {
        return array_sum(array_column($lines, 'count'));
    }
}
