<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync;

use Goemktg\Seat\SeatCustomPlugin\Conformance\EftParser;

/** What changed between two EFT texts, as lines a person can read. */
final class EftDiff
{
    /**
     * @return array{removed: list<string>, added: list<string>}  "Name ×n" lines
     */
    public static function between(string $old, string $new): array
    {
        $before = self::counts($old);
        $after = self::counts($new);
        $removed = [];
        $added = [];
        foreach ($before + $after as $name => $_) {
            $delta = ($after[$name] ?? 0) - ($before[$name] ?? 0);
            if ($delta < 0) {
                $removed[] = sprintf('%s ×%d', $name, -$delta);
            } elseif ($delta > 0) {
                $added[] = sprintf('%s ×%d', $name, $delta);
            }
        }
        sort($removed);
        sort($added);

        return ['removed' => $removed, 'added' => $added];
    }

    /** @return array<string, int> item name => quantity over every slot and the cargo */
    private static function counts(string $eft): array
    {
        try {
            $parsed = EftParser::parse($eft);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $counts = [];
        foreach ($parsed->slots as $modules) {
            foreach ($modules as $name => $qty) {
                $counts[(string) $name] = ($counts[(string) $name] ?? 0) + $qty;
            }
        }
        foreach ($parsed->cargoLines as $line) {
            $qty = 1;
            $name = trim($line);
            if (preg_match('/^(.*\S)\s+x(\d+)$/i', $name, $m) === 1) {
                [$name, $qty] = [$m[1], (int) $m[2]];
            }
            if ($name !== '' && ! str_starts_with($name, '[')) {
                $counts[$name] = ($counts[$name] ?? 0) + $qty;
            }
        }

        return $counts;
    }
}
