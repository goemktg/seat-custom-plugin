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

    /** Fits the last run found already brought in by another followed doctrine. */
    public static function sharedCount(?string $lastReport): int
    {
        $report = $lastReport === null ? null : json_decode($lastReport, true);

        return is_array($report) && is_array($report['shared'] ?? null) ? count($report['shared']) : 0;
    }

    /**
     * Which fits followed doctrines last reported as shared, and by which of them.
     *
     * @param  iterable<array{name: string, report: ?string}>  $sources  followed doctrines only
     * @return array<int, list<string>>  fit id => names of the doctrines that share it besides its own
     */
    public static function sharedFits(iterable $sources): array
    {
        $fits = [];
        foreach ($sources as $source) {
            $report = $source['report'] === null ? null : json_decode($source['report'], true);
            foreach (is_array($report) && is_array($report['shared'] ?? null) ? $report['shared'] : [] as $row) {
                $id = is_array($row) ? ($row['fitId'] ?? null) : null;
                if (is_int($id) && $id > 0 && ! in_array($source['name'], $fits[$id] ?? [], true)) {
                    $fits[$id][] = $source['name'];
                }
            }
        }

        return $fits;
    }

    /** @return array{held: int, failed: int} */
    public static function problems(?string $lastReport): array
    {
        $report = $lastReport === null ? null : json_decode($lastReport, true);
        $count = static fn (string $key): int => is_array($report) && is_array($report[$key] ?? null) ? count($report[$key]) : 0;

        return ['held' => $count('held'), 'failed' => $count('failed')];
    }
}
