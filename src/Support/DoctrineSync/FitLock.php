<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync;

/**
 * A fit synced from the doctrine site takes its doctrine, name and EFT from
 * the site. Until its source is gone, the fit form cannot change them; the
 * plugin's own values (required counts, bays, container, sort order) stay
 * editable.
 */
final class FitLock
{
    public const FIELDS = ['doctrine', 'fit_name', 'eft'];

    public static function locked(?int $sourceId, ?string $syncState): bool
    {
        return $sourceId !== null && $syncState !== 'missing';
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
