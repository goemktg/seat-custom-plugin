<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support;

use Goemktg\Seat\SeatCustomPlugin\Conformance\HangarReport;

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
        // only the trailing key clause: the duplicate value before it is user text.
        // MySQL 8 writes 'table.key', MariaDB just 'key'.
        $key = preg_match("/for key '([^']*)'\\s*$/", $driverMessage, $m) === 1 ? $m[1] : '';
        if ($key === self::HULL_KEY || str_ends_with($key, '.' . self::HULL_KEY)) {
            return self::RACE_SAME_HULL;
        }
        if ($key === self::DOCTRINE_KEY || str_ends_with($key, '.' . self::DOCTRINE_KEY)) {
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
     * The stored fit on the same hull whose name Hangar Check trims to the new
     * (already trimmed) name, or null. The column collation misses rows kept
     * from releases before 0.1.7 with leading or trailing non-ASCII space.
     *
     * @template T of object{fit_name: string}
     *
     * @param  iterable<T>  $sameHull
     * @return T|null
     */
    public static function sameNameKey(iterable $sameHull, string $fitName): ?object
    {
        foreach ($sameHull as $row) {
            if (HangarReport::nameKey((string) $row->fit_name) === $fitName) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Stored fits Hangar Check would compare with the same ship although the
     * unique key may see them as different: their names trim to one
     * HangarReport::nameKey on one hull. Releases before 0.1.7 trimmed ASCII
     * whitespace only, so names that differ by leading or trailing non-ASCII
     * space (NBSP, U+3000, ...) can be stored side by side.
     *
     * @param  iterable<object{id: int|string, corporation_id: int|string, ship_type_id: int|string, fit_name: string}>  $rows
     * @return list<object{corporation_id: int, ship_type_id: int, fit_name: string, fits: int, ids: string}>
     */
    public static function nameKeyConflicts(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = HangarReport::nameKey((string) $row->fit_name);
            if ($key === '') {
                continue; // never paired with a ship
            }
            $groups[(int) $row->corporation_id][(int) $row->ship_type_id][$key][] = (int) $row->id;
        }

        $out = [];
        foreach ($groups as $corporationId => $hulls) {
            foreach ($hulls as $shipTypeId => $names) {
                foreach ($names as $name => $ids) {
                    if (count($ids) > 1) {
                        sort($ids);
                        $out[] = (object) [
                            'corporation_id' => $corporationId,
                            'ship_type_id' => $shipTypeId,
                            'fit_name' => (string) $name,
                            'fits' => count($ids),
                            'ids' => implode(',', $ids),
                        ];
                    }
                }
            }
        }

        return $out;
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
                // a stored name may hold control characters; keep each group on one line
                addcslashes((string) $row->fit_name, "\0..\37\177\\"),
                $row->ids,
                isset($row->fits) ? sprintf(' (%d fits)', $row->fits) : '',
            );
        }
        if ($lines === []) {
            return null;
        }

        return "Doctrine fits on the same hull share a name (upper and lower case, accents and leading or trailing spaces ignored):\n"
            . implode("\n", $lines)
            . "\nRename or delete all but one fit in each group on the Doctrine Fits page, then run the migration again.";
    }
}
