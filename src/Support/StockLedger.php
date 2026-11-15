<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support;

use InvalidArgumentException;

/**
 * Rules of the per fit and staging stock ledger. Stock is the sum of the
 * signed quantities: purchases add ships, losses remove them, adjustments
 * correct the count either way. The table's CHECK constraints repeat these.
 */
final class StockLedger
{
    /** kind => label shown in the UI */
    public const KINDS = [
        'purchase' => 'Purchase',
        'loss' => 'Loss',
        'adjust' => 'Adjust',
    ];

    /** Largest number of ships one record may move. */
    public const MAX_SHIPS = 10000;

    /**
     * The signed quantity to store for a record of $ships ships.
     * Purchases and losses take a positive count; an adjustment carries its own sign.
     *
     * @throws InvalidArgumentException when the kind is unknown or the count does not fit it
     */
    public static function signed(string $kind, int $ships): int
    {
        if (! isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException('Choose purchase, loss or adjust.');
        }
        if (abs($ships) > self::MAX_SHIPS) {
            throw new InvalidArgumentException(sprintf('One record can move at most %d ships.', self::MAX_SHIPS));
        }
        if ($kind === 'adjust') {
            if ($ships === 0) {
                throw new InvalidArgumentException('An adjustment must add or remove at least one ship.');
            }

            return $ships;
        }
        if ($ships < 1) {
            throw new InvalidArgumentException('Enter at least one ship.');
        }

        return $kind === 'loss' ? -$ships : $ships;
    }

    /**
     * Ships the ledger counts beyond those in the hangar (handed out, or not yet recorded as lost).
     */
    public static function outsideHangar(int $stock, int $inHangar): int
    {
        return max(0, $stock - $inHangar);
    }
}
