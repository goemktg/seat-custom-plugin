<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync;

/** What a sync run will do, or did, about one fit. */
final class SyncAction
{
    public const ADD = 'added';
    public const UPDATE = 'updated';
    public const LINK = 'linked';
    public const RETURNED = 'returned';
    /** a shared fit another followed doctrine brought in, taken over because that doctrine let go of it */
    public const TAKE_OVER = 'taken_over';
    public const UNCHANGED = 'unchanged';
    /** a shared fit another followed doctrine already brought in: nothing to write, nothing wrong */
    public const SHARED = 'shared';
    public const MISSING = 'missing';
    public const HELD = 'held';
    public const FAILED = 'failed';

    /** Kinds that write a fit. */
    public const WRITES = [self::ADD, self::UPDATE, self::LINK, self::RETURNED, self::TAKE_OVER];

    /** Why a fit is held when a hand-made fit has its name; the import window offers to link it. */
    public const HAND_FIT = 'A fit added by hand has this name on this hull.';

    public function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly ?UpstreamFit $fit = null,
        public readonly ?int $shipTypeId = null,
        public readonly ?int $fitId = null,
        public readonly ?string $detail = null,
    ) {
    }

    public function writes(): bool
    {
        return in_array($this->kind, self::WRITES, true);
    }

    /** A held hand-made fit the user may hand over to the doctrine. */
    public function linkable(): bool
    {
        return $this->kind === self::HELD && $this->fitId !== null && $this->detail === self::HAND_FIT;
    }
}
