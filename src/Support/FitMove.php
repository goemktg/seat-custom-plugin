<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support;

/**
 * Rules for moving a fit's required ship counts and stock ledger to another
 * fit, for a fit the doctrine site no longer has (renamed or replaced there).
 */
final class FitMove
{
    /** Only a synced fit gone from the doctrine site is moved from; the others are still in use. */
    public static function canMoveFrom(?int $sourceId, ?string $syncState): bool
    {
        return $sourceId !== null && $syncState === 'missing';
    }

    /**
     * Stagings where both fits have a required count. The move is refused
     * then: which count is right is the user's call, made on the fit's page.
     *
     * @param  array<int, int>  $from  staging id => required count of the fit moved from
     * @param  array<int, int>  $to  staging id => required count of the fit moved to
     * @return list<int>  staging ids, ascending
     */
    public static function clashes(array $from, array $to): array
    {
        $clashes = [];
        foreach ($from as $stagingId => $count) {
            if ($count > 0 && ($to[$stagingId] ?? 0) > 0) {
                $clashes[] = (int) $stagingId;
            }
        }
        sort($clashes);

        return $clashes;
    }

    public static function summary(string $from, string $to, int $stagings, int $entries): string
    {
        if ($stagings === 0 && $entries === 0) {
            return sprintf('Moved nothing from "%s" to "%s": it had no required counts or stock entries.', $from, $to);
        }

        return sprintf('Moved required counts at %d %s and %d %s from "%s" to "%s".',
            $stagings, $stagings === 1 ? 'staging' : 'stagings', $entries, $entries === 1 ? 'stock entry' : 'stock entries', $from, $to);
    }
}
