<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support;

/**
 * Wording and reporting for the two uniqueness rules on doctrine fits.
 *
 * Within one corporation a fit name must be unique per doctrine and per hull.
 * The hull rule gives every ship in Hangar Check at most one candidate fit, so
 * storing many same-name fits cannot multiply the matching work of a page view.
 * Both rules compare names the way the database collation does (upper and
 * lower case and accents ignored).
 */
final class DuplicateFit
{
    public const DOCTRINE_KEY = 'cp_doctrine_fits_corp_doctrine_name_unique';

    public const HULL_KEY = 'cp_doctrine_fits_corp_ship_name_unique';

    public const RACE_SAME_DOCTRINE = 'A fit with this doctrine and name was just saved by someone else. Choose another name.';

    public const RACE_SAME_HULL = 'A fit on this hull with this name was just saved by someone else. Choose another name.';

    public const RACE_EITHER = 'A fit with this name was just saved by someone else in this doctrine or on this hull. Choose another name.';

    /** Message for a duplicate-key error raised by a concurrent save, chosen by the key the driver names. */
    public static function messageForDriverError(string $driverMessage): string
    {
        if (str_contains($driverMessage, self::HULL_KEY)) {
            return self::RACE_SAME_HULL;
        }
        if (str_contains($driverMessage, self::DOCTRINE_KEY)) {
            return self::RACE_SAME_DOCTRINE;
        }

        return self::RACE_EITHER;
    }

    public static function sameHullMessage(string $existingName, string $existingDoctrine): string
    {
        return sprintf(
            'Another fit on this hull is already named "%s" (doctrine "%s"). Hangar Check pairs a ship with one fit by name and hull, so give this fit a different name. Names that differ only in upper and lower case or accents count as the same.',
            $existingName,
            $existingDoctrine,
        );
    }

    /**
     * Text for a migration that cannot add the hull rule because stored fits
     * already break it, or null when none do.
     *
     * @param  iterable<object{corporation_id: int|string, ship_type_id: int|string, fit_name: string, ids: string}>  $conflicts
     */
    public static function migrationReport(iterable $conflicts): ?string
    {
        $lines = [];
        foreach ($conflicts as $row) {
            $lines[] = sprintf(
                '- corporation %s, hull type %s, name "%s": fit ids %s%s',
                $row->corporation_id,
                $row->ship_type_id,
                $row->fit_name,
                $row->ids,
                isset($row->fits) ? sprintf(' (%d fits)', $row->fits) : '',
            );
        }
        if ($lines === []) {
            return null;
        }

        return "Doctrine fits on the same hull share a name (upper and lower case and accents ignored):\n"
            . implode("\n", $lines)
            . "\nRename or delete all but one fit in each group on the Doctrine Fits page, then run the migration again.";
    }
}
