<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync;

/** Cleans what the import window and the settings form send. */
final class ImportInput
{
    /** doctrines one import may touch; each is a request to the doctrine site */
    public const MAX_DOCTRINES = 20;

    /** @return list<int> */
    public static function corporationIds(mixed $sent): array
    {
        if (! is_array($sent)) {
            return [];
        }
        $ids = self::positiveIds($sent);
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    public static function slugs(mixed $sent): array
    {
        if (! is_array($sent)) {
            return [];
        }
        $out = [];
        foreach ($sent as $slug) {
            if (is_string($slug) && preg_match(UpstreamDoctrine::SLUG, $slug) === 1) {
                $out[$slug] = true;
            }
        }

        return array_slice(array_keys($out), 0, self::MAX_DOCTRINES);
    }

    /** @return list<int> manual fits the user ticked to link for this doctrine */
    public static function linkIds(mixed $sent, string $slug): array
    {
        $list = is_array($sent) ? ($sent[$slug] ?? null) : null;

        return is_array($list) ? self::positiveIds($list) : [];
    }

    /** @return list<int> */
    private static function positiveIds(array $sent): array
    {
        $ids = [];
        foreach ($sent as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }
}
