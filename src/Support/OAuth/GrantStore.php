<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\OAuth;

/**
 * Where one-time codes and access tokens live until they expire.
 *
 * Every key is the SHA-256 of the code or token, never the value itself.
 */
interface GrantStore
{
    /** @param  array<string, mixed>  $payload */
    public function putCode(string $hash, array $payload, int $seconds): void;

    /**
     * Hands the code out at most once, even to two requests racing for it.
     * Returns null when the code is unknown, expired or already taken.
     *
     * @return array<string, mixed>|null
     */
    public function takeCode(string $hash, int $seconds): ?array;

    /**
     * @param  array<string, mixed>  $payload
     * @param  string  $codeHash  the code the token was issued for, so reusing it can revoke the token
     */
    public function putToken(string $hash, array $payload, int $seconds, string $codeHash): void;

    /** @return array<string, mixed>|null */
    public function token(string $hash): ?array;

    /**
     * Marks the code as presented again, then drops the token issued for it, if any.
     * The mark must be visible to codeReused() before the token is looked up.
     */
    public function revokeTokenIssuedFrom(string $codeHash, int $seconds): void;

    /** True once revokeTokenIssuedFrom() has marked this code. */
    public function codeReused(string $codeHash): bool;
}
