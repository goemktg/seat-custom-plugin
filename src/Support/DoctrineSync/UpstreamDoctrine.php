<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync;

use Goemktg\Seat\SeatCustomPlugin\Conformance\EftParser;
use Goemktg\Seat\SeatCustomPlugin\Conformance\FitName;
use Goemktg\Seat\SeatCustomPlugin\Conformance\HangarReport;

/**
 * A doctrine as doctrine.nisuwaz.com serves it, read defensively: the response
 * comes from another server and nothing in it is trusted.
 */
final class UpstreamDoctrine
{
    public const STATUSES = ['active', 'testing', 'retired'];

    public const SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';

    private const MAX_NAME = 100;

    private const MAX_EFT_BYTES = 20000;

    private const MAX_FITS = 300;

    /**
     * @param  list<UpstreamFit>  $fits  in upstream order
     * @param  list<string>  $unreadable  labels of fits whose EFT could not be read
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $status,
        public readonly int $version,
        public readonly array $fits,
        public readonly array $unreadable,
    ) {
    }

    /**
     * @param  mixed  $payload  decoded `GET /api/doctrines/:slug` response
     *
     * @throws \InvalidArgumentException when the response is not a doctrine
     */
    public static function fromResponse(mixed $payload): self
    {
        $doctrine = is_array($payload) ? ($payload['doctrine'] ?? null) : null;
        $version = is_array($payload) ? ($payload['version'] ?? null) : null;
        if (! is_array($doctrine) || ! is_int($version) || $version < 1) {
            throw new \InvalidArgumentException('The response is not a doctrine.');
        }

        $slug = $doctrine['slug'] ?? null;
        $name = HangarReport::nameKey(is_string($doctrine['name'] ?? null) ? $doctrine['name'] : '');
        $status = $doctrine['status'] ?? null;
        $roles = $doctrine['roles'] ?? null;
        if (! is_string($slug) || preg_match(self::SLUG, $slug) !== 1
            || $name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/\p{Cc}/u', $name) === 1
            || ! in_array($status, self::STATUSES, true) || ! is_array($roles)) {
            throw new \InvalidArgumentException('The response is not a doctrine.');
        }

        $fits = [];
        $unreadable = [];
        foreach ($roles as $role) {
            foreach (is_array($role) && is_array($role['fits'] ?? null) ? $role['fits'] : [] as $fit) {
                if (count($fits) + count($unreadable) >= self::MAX_FITS) {
                    break 2;
                }
                $read = self::fit($fit, $name);
                if ($read === null) {
                    $unreadable[] = self::roughLabel($fit);
                } else {
                    $fits[] = $read;
                }
            }
        }

        return new self($slug, $name, $status, $version, $fits, $unreadable);
    }

    private static function fit(mixed $fit, string $doctrine): ?UpstreamFit
    {
        $eft = is_array($fit) ? ($fit['eft'] ?? null) : null;
        if (! is_string($eft) || strlen($eft) > self::MAX_EFT_BYTES || ! mb_check_encoding($eft, 'UTF-8')
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $eft) === 1) {
            return null;
        }
        try {
            $parsed = EftParser::parse($eft);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $fitName = FitName::resolve(null, $parsed->fitName, $doctrine);
        if ($fitName === '' || mb_strlen($fitName) > self::MAX_NAME) {
            return null;
        }

        // a whole JSON number only: "42", 42.0 or true would be a different id after a cast
        $shared = $fit['shared'] ?? null;

        return new UpstreamFit(UpstreamFit::normalize($eft), $parsed->shipName, $fitName, is_int($shared) && $shared > 0 ? $shared : null);
    }

    private static function roughLabel(mixed $fit): string
    {
        $name = is_array($fit) && is_string($fit['name'] ?? null) ? $fit['name'] : '';
        $ship = is_array($fit) && is_string($fit['ship'] ?? null) ? $fit['ship'] : '';
        $label = trim(sprintf('%s (%s)', $name, $ship));

        return mb_substr(preg_replace('/\p{Cc}/u', ' ', $label === '()' ? 'unnamed fit' : $label) ?? 'unnamed fit', 0, 200);
    }
}
