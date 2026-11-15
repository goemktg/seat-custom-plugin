<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support;

/**
 * The corporation a user last opened in Hangar Check or Doctrine Fits, kept in a
 * browser cookie so the menu entries can skip the corporation list.
 *
 * The cookie is only a preference: a value is used only when it names a
 * corporation the user may open right now.
 */
final class LastCorporation
{
    public const COOKIE = 'custom_plugin_corporation';

    /** One year, in minutes (Laravel cookie lifetime unit). */
    public const MINUTES = 525600;

    /**
     * @param  list<int|string>  $allowedIds  corporations the user may open now (the DB driver may return strings)
     * @param  mixed  $remembered  the raw cookie value
     */
    public static function pick(array $allowedIds, mixed $remembered): ?int
    {
        if (! is_string($remembered) || preg_match('/^[1-9][0-9]{0,18}$/', $remembered) !== 1) {
            return null;
        }

        $id = (int) $remembered;

        return in_array($id, array_map('intval', $allowedIds), true) ? $id : null;
    }
}
