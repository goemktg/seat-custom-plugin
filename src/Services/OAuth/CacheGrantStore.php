<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Services\OAuth;

use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\GrantStore;
use Illuminate\Contracts\Cache\Repository;

/**
 * GrantStore on the SeAT cache (Redis). The cache drops each key when it
 * expires; OAuthServer also checks the expiry kept inside the value.
 *
 * Single use of a code relies on add() being atomic, which holds for the
 * Redis, Memcached and database stores SeAT can use, not for the file store.
 */
final class CacheGrantStore implements GrantStore
{
    private const PREFIX = 'custom-plugin:oauth:';

    public function __construct(private readonly Repository $cache)
    {
    }

    public function putCode(string $hash, array $payload, int $seconds): void
    {
        $this->cache->put(self::PREFIX . 'code:' . $hash, $payload, $seconds);
    }

    public function takeCode(string $hash, int $seconds): ?array
    {
        // add() is atomic (SET NX on Redis), so only one request can claim a code
        if (! $this->cache->add(self::PREFIX . 'claim:' . $hash, true, $seconds)) {
            return null;
        }
        $payload = $this->cache->pull(self::PREFIX . 'code:' . $hash);

        return is_array($payload) ? $payload : null;
    }

    public function putToken(string $hash, array $payload, int $seconds, string $codeHash): void
    {
        $this->cache->put(self::PREFIX . 'token:' . $hash, $payload, $seconds);
        $this->cache->put(self::PREFIX . 'code-token:' . $codeHash, $hash, $seconds);
    }

    public function token(string $hash): ?array
    {
        $payload = $this->cache->get(self::PREFIX . 'token:' . $hash);

        return is_array($payload) ? $payload : null;
    }

    public function revokeTokenIssuedFrom(string $codeHash, int $seconds): void
    {
        $this->cache->put(self::PREFIX . 'reused:' . $codeHash, true, $seconds);
        $token = $this->cache->pull(self::PREFIX . 'code-token:' . $codeHash);
        if (is_string($token)) {
            $this->cache->forget(self::PREFIX . 'token:' . $token);
        }
    }

    public function codeReused(string $codeHash): bool
    {
        return $this->cache->get(self::PREFIX . 'reused:' . $codeHash) === true;
    }
}
