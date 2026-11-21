<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Fakes;

use Closure;
use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\GrantStore;

/**
 * GrantStore kept in an array, with lifetimes read from the same clock the server uses,
 * so tests can see what the Redis cache would keep and drop.
 */
final class InMemoryGrantStore implements GrantStore
{
    /** @var array<string, array{value: mixed, until: int}> */
    public array $items = [];

    /** @var (\Closure(): void)|null runs inside putToken, after the token is stored, to interleave a second request */
    public ?Closure $afterPutToken = null;

    public function __construct(private readonly Closure $now)
    {
    }

    public function putCode(string $hash, array $payload, int $seconds): void
    {
        $this->put('code:' . $hash, $payload, $seconds);
    }

    public function takeCode(string $hash, int $seconds): ?array
    {
        if ($this->get('claim:' . $hash) !== null) {
            return null;
        }
        $this->put('claim:' . $hash, true, $seconds);
        $payload = $this->get('code:' . $hash);
        unset($this->items['code:' . $hash]);

        return $payload;
    }

    public function putToken(string $hash, array $payload, int $seconds, string $codeHash): void
    {
        $this->put('token:' . $hash, $payload, $seconds);
        $this->put('code-token:' . $codeHash, $hash, $seconds);
        if ($this->afterPutToken !== null) {
            $hook = $this->afterPutToken;
            $this->afterPutToken = null;
            $hook();
        }
    }

    public function token(string $hash): ?array
    {
        return $this->get('token:' . $hash);
    }

    public function revokeTokenIssuedFrom(string $codeHash, int $seconds): void
    {
        $this->put('reused:' . $codeHash, true, $seconds);
        $token = $this->get('code-token:' . $codeHash);
        unset($this->items['code-token:' . $codeHash]);
        if (is_string($token)) {
            unset($this->items['token:' . $token]);
        }
    }

    public function codeReused(string $codeHash): bool
    {
        return $this->get('reused:' . $codeHash) === true;
    }

    /** @return list<string> keys still alive */
    public function keys(): array
    {
        return array_keys(array_filter($this->items, fn (array $item) => $item['until'] > ($this->now)()));
    }

    private function put(string $key, mixed $value, int $seconds): void
    {
        $this->items[$key] = ['value' => $value, 'until' => ($this->now)() + $seconds];
    }

    private function get(string $key): mixed
    {
        $item = $this->items[$key] ?? null;

        return $item !== null && $item['until'] > ($this->now)() ? $item['value'] : null;
    }
}
