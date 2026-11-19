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
        // a list, not array keys: PHP would turn an all-digit slug such as "123" into an int key
        $out = [];
        foreach ($sent as $slug) {
            if (is_string($slug) && preg_match(UpstreamDoctrine::SLUG, $slug) === 1 && ! in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }

        return array_slice($out, 0, self::MAX_DOCTRINES);
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
