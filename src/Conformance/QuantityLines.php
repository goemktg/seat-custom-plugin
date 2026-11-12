<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

/**
 * Resolves "Item name xN" / "Item name N" lines to type ids.
 *
 * A line is first looked up whole, so names that end in digits
 * ("Cap Booster 3200") stay intact; only when that fails is a trailing bare
 * number read as the quantity.
 */
final class QuantityLines
{
    /** Larger quantities are treated as typos rather than requirements. */
    public const MAX_QTY = 100_000_000;

    /**
     * @param  iterable<string>  $lines
     * @param  callable(list<string>): array<string, array{id: int, name: string}>  $lookup
     *         receives names, returns matches keyed by the lower-cased name
     */
    public static function resolve(iterable $lines, callable $lookup): ResolvedQuantities
    {
        $candidates = [];
        foreach ($lines as $raw) {
            $candidate = self::candidate((string) $raw);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }
        if ($candidates === []) {
            return new ResolvedQuantities([], [], []);
        }

        $first = $lookup(array_values(array_unique(array_column($candidates, 'name'))));
        $retryNames = [];
        foreach ($candidates as $c) {
            if (! isset($first[mb_strtolower($c['name'])]) && $c['fallback'] !== null) {
                $retryNames[] = $c['fallback']['name'];
            }
        }
        $retry = $retryNames === [] ? [] : $lookup(array_values(array_unique($retryNames)));

        $counts = [];
        $names = [];
        $unresolved = [];
        foreach ($candidates as $c) {
            $hit = $first[mb_strtolower($c['name'])] ?? null;
            $qty = $c['qty'];
            if ($hit === null && $c['fallback'] !== null) {
                $hit = $retry[mb_strtolower($c['fallback']['name'])] ?? null;
                $qty = $c['fallback']['qty'];
            }
            if ($hit === null || $qty < 1 || $qty > self::MAX_QTY) {
                $unresolved[$c['line']] = true;
                continue;
            }
            $id = (int) $hit['id'];
            $counts[$id] = ($counts[$id] ?? 0) + $qty;
            $names[$id] = (string) $hit['name'];
        }

        $unresolvedLines = array_map('strval', array_keys($unresolved));
        sort($unresolvedLines);

        return new ResolvedQuantities($counts, $names, $unresolvedLines);
    }

    public static function normalise(string $line): string
    {
        $line = str_replace(["\r", "\u{00A0}"], ' ', $line);
        $line = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $line);

        return trim((string) preg_replace('/\s+/u', ' ', $line));
    }

    /**
     * @return array{line: string, name: string, qty: int, fallback: ?array{name: string, qty: int}}|null
     */
    private static function candidate(string $raw): ?array
    {
        $line = self::normalise($raw);
        if ($line === '' || str_starts_with($line, '[')) {
            return null;
        }
        if (preg_match('/^(.*?)\s+x(\d+)$/i', $line, $m) && trim($m[1]) !== '') {
            return ['line' => $line, 'name' => trim($m[1]), 'qty' => (int) $m[2], 'fallback' => null];
        }
        $fallback = null;
        if (preg_match('/^(.*\S)\s+(\d+)$/', $line, $m) && trim($m[1]) !== '') {
            $fallback = ['name' => trim($m[1]), 'qty' => (int) $m[2]];
        }

        return ['line' => $line, 'name' => $line, 'qty' => 1, 'fallback' => $fallback];
    }
}
