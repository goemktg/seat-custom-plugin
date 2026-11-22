<?php

namespace Goemktg\Seat\SeatCustomPlugin\Support;

/**
 * A Discord user ID as the discord-links API accepts it: 1 to 20 ASCII digits.
 * Discord IDs are 64-bit snowflakes (at most 20 digits), and seat-connector keeps them
 * as strings in seat_connector_users.connector_id, so the value stays a string.
 */
final class DiscordId
{
    public static function parse(mixed $value): ?string
    {
        // \z, not $: "$" would also let a trailing newline through
        return is_string($value) && preg_match('/\A[0-9]{1,20}\z/', $value) === 1 ? $value : null;
    }
}
