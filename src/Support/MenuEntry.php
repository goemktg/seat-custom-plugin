<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support;

/**
 * The sidebar entry a plugin page belongs to.
 *
 * SeAT marks a sidebar entry active only when the current URL equals the
 * entry's URL exactly. Hangar Check and Doctrine Fits redirect to a
 * per-corporation URL, so their entries would never light up; the pages use
 * this to mark the right entry themselves.
 */
final class MenuEntry
{
    /** Route name prefix => route of the sidebar entry (see Config/package.sidebar.php). */
    private const ENTRIES = [
        'custom-plugin.hangar.' => 'custom-plugin.hangar.index',
        'custom-plugin.fits.' => 'custom-plugin.fits.pick',
        'custom-plugin.stagings.' => 'custom-plugin.fits.pick',
        'custom-plugin.settings' => 'custom-plugin.settings',
    ];

    public static function for(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        foreach (self::ENTRIES as $prefix => $entry) {
            if ($routeName === $prefix || (str_ends_with($prefix, '.') && str_starts_with($routeName, $prefix))) {
                return $entry;
            }
        }

        return null;
    }
}
