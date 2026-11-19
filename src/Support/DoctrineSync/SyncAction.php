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
    public const UNCHANGED = 'unchanged';
    public const MISSING = 'missing';
    public const HELD = 'held';
    public const FAILED = 'failed';

    /** Kinds that write a fit. */
    public const WRITES = [self::ADD, self::UPDATE, self::LINK, self::RETURNED];

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
}
