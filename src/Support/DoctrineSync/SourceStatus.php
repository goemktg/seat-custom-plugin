<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync;

/** How a followed doctrine is doing, for the status card. */
final class SourceStatus
{
    public const WAITING = 'waiting';
    public const OK = 'ok';
    public const ATTENTION = 'attention';
    public const ERROR = 'error';

    public static function of(?string $lastCheckedAt, ?string $lastError, ?string $lastReport): string
    {
        if ($lastError !== null && $lastError !== '') {
            return self::ERROR;
        }
        if ($lastCheckedAt === null) {
            return self::WAITING;
        }
        $problems = self::problems($lastReport);

        return $problems['held'] + $problems['failed'] > 0 ? self::ATTENTION : self::OK;
    }

    /**
     * Whether the last run already reported this held or failed fit for the
     * same reason; the hourly sync then leaves it out of the history.
     */
    public static function alreadyReported(?string $lastReport, string $kind, string $fit, ?string $detail): bool
    {
        $report = $lastReport === null ? null : json_decode($lastReport, true);
        foreach (is_array($report) && is_array($report[$kind] ?? null) ? $report[$kind] : [] as $row) {
            if (is_array($row) && ($row['fit'] ?? null) === $fit && ($row['detail'] ?? null) === (string) $detail) {
                return true;
            }
        }

        return false;
    }

    /** @return array{held: int, failed: int} */
    public static function problems(?string $lastReport): array
    {
        $report = $lastReport === null ? null : json_decode($lastReport, true);
        $count = static fn (string $key): int => is_array($report) && is_array($report[$key] ?? null) ? count($report[$key]) : 0;

        return ['held' => $count('held'), 'failed' => $count('failed')];
    }
}
