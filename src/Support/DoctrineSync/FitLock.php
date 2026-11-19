<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync;

/**
 * A fit synced from the doctrine site takes its doctrine, name and EFT from
 * the site. While the site still has it and the corporation still syncs, the
 * fit form cannot change them; the plugin's own values (required counts,
 * bays, container, sort order) stay editable.
 */
final class FitLock
{
    public const FIELDS = ['doctrine', 'fit_name', 'eft'];

    /** @param bool $syncOn whether the doctrine site sync is on for the fit's corporation */
    public static function locked(?int $sourceId, ?string $syncState, bool $syncOn): bool
    {
        return $syncOn && $sourceId !== null && $syncState !== 'missing';
    }

    /**
     * @param  array<string, mixed>  $sent  the form input
     * @param  array<string, mixed>  $stored  the fit's current values
     * @return array<string, mixed> the input with the locked fields put back
     */
    public static function enforce(array $sent, array $stored): array
    {
        foreach (self::FIELDS as $field) {
            $sent[$field] = $stored[$field] ?? null;
        }

        return $sent;
    }
}
