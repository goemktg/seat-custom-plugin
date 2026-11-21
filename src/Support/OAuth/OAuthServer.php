<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\OAuth;

use Closure;

/**
 * OAuth 2.0 authorization code grant with PKCE (S256 only) for the one
 * registered client. No consent screen, no refresh tokens, no tables:
 * codes live 60 seconds and access tokens 5 minutes in the GrantStore,
 * keyed by their SHA-256.
 */
final class OAuthServer
{
    public const CODE_SECONDS = 60;
    public const TOKEN_SECONDS = 300;
    public const STATE_MAX = 500;

    /** 32 random bytes in base64url without padding */
    private const SECRET_PATTERN = '/^[A-Za-z0-9_-]{43}\z/';
    /** RFC 7636 section 4.1 */
    private const VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}\z/';

    private readonly Closure $now;

    public function __construct(
        private readonly ClientConfig $client,
        private readonly GrantStore $store,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    public function enabled(): bool
    {
        return $this->client->enabled();
    }

    /**
     * @param  array<string, mixed>  $query  the authorize request query
     * @param  bool  $allowed  whether the signed-in user may use the client
     */
    public function authorize(array $query, int $userId, bool $allowed): AuthorizeResult
    {
        if (! $this->client->matches($query['client_id'] ?? null, $query['redirect_uri'] ?? null)) {
            return AuthorizeResult::reject('The application or its return address is not registered with SeAT.');
        }

        $state = $query['state'] ?? null;
        if (! is_string($state) || $state === '' || strlen($state) > self::STATE_MAX) {
            return $this->back(['error' => 'invalid_request']);
        }
        $challenge = $query['code_challenge'] ?? null;
        if (($query['response_type'] ?? null) !== 'code'
            || ($query['code_challenge_method'] ?? null) !== 'S256'
            || ! is_string($challenge) || preg_match(self::SECRET_PATTERN, $challenge) !== 1) {
            return $this->back(['error' => 'invalid_request', 'state' => $state]);
        }
        if (! $allowed) {
            return $this->back(['error' => 'access_denied', 'state' => $state]);
        }

        $code = self::randomSecret();
        $this->store->putCode(self::hash($code), [
            'user_id' => $userId,
            'client_id' => $this->client->clientId(),
            'redirect_uri' => $this->client->redirectUri(),
            'code_challenge' => $challenge,
            'expires_at' => ($this->now)() + self::CODE_SECONDS,
        ], self::CODE_SECONDS);

        return $this->back(['code' => $code, 'state' => $state]);
    }

    /** @param  array<string, mixed>  $form  the token request body */
    public function authenticatesClient(array $form): bool
    {
        return $this->client->authenticates($form['client_id'] ?? null, $form['client_secret'] ?? null);
    }

    /** @param  array<string, mixed>  $form  the token request body */
    public function token(array $form): TokenResult
    {
        if (! $this->authenticatesClient($form)) {
            return TokenResult::error('invalid_client', 401);
        }
        $grantType = $form['grant_type'] ?? null;
        if (! is_string($grantType) || $grantType === '') {
            return TokenResult::error('invalid_request');
        }
        if ($grantType !== 'authorization_code') {
            return TokenResult::error('unsupported_grant_type');
        }
        $code = $form['code'] ?? null;
        $redirectUri = $form['redirect_uri'] ?? null;
        $verifier = $form['code_verifier'] ?? null;
        if (! is_string($code) || $code === '' || ! is_string($redirectUri) || $redirectUri === ''
            || ! is_string($verifier) || preg_match(self::VERIFIER_PATTERN, $verifier) !== 1) {
            return TokenResult::error('invalid_request');
        }
        if (preg_match(self::SECRET_PATTERN, $code) !== 1) {
            return TokenResult::error('invalid_grant');
        }

        $codeHash = self::hash($code);
        $grant = $this->store->takeCode($codeHash, self::CODE_SECONDS);
        if ($grant === null) {
            // a code presented again may have been stolen: drop what it was exchanged for
            $this->store->revokeTokenIssuedFrom($codeHash, self::TOKEN_SECONDS);

            return TokenResult::error('invalid_grant');
        }
        $now = ($this->now)();
        if (! is_int($grant['expires_at'] ?? null) || $grant['expires_at'] <= $now
            || ! is_string($grant['client_id'] ?? null) || ! hash_equals($grant['client_id'], (string) $form['client_id'])
            || ! is_string($grant['redirect_uri'] ?? null) || ! hash_equals($grant['redirect_uri'], $redirectUri)
            || ! is_string($grant['code_challenge'] ?? null) || ! hash_equals($grant['code_challenge'], self::s256($verifier))
            || ! is_int($grant['user_id'] ?? null)) {
            return TokenResult::error('invalid_grant');
        }

        $token = self::randomSecret();
        $this->store->putToken(self::hash($token), [
            'user_id' => $grant['user_id'],
            'client_id' => $grant['client_id'],
            'expires_at' => $now + self::TOKEN_SECONDS,
        ], self::TOKEN_SECONDS, $codeHash);

        // A second request for the same code may have arrived while this one was issuing.
        // It marks the code before revoking, and this token is stored before this check,
        // so either it already removed the token or the mark is visible here.
        if ($this->store->codeReused($codeHash)) {
            $this->store->revokeTokenIssuedFrom($codeHash, self::TOKEN_SECONDS);

            return TokenResult::error('invalid_grant');
        }

        return TokenResult::issued($token, self::TOKEN_SECONDS);
    }

    /** The SeAT user id behind a live "Authorization: Bearer" header, or null. */
    public function userIdFor(?string $authorization): ?int
    {
        if (! $this->enabled() || $authorization === null
            || preg_match('/^Bearer ([A-Za-z0-9_-]{43})\z/i', $authorization, $m) !== 1) {
            return null;
        }
        $grant = $this->store->token(self::hash($m[1]));
        if ($grant === null
            || ! is_int($grant['expires_at'] ?? null) || $grant['expires_at'] <= ($this->now)()
            || ! is_string($grant['client_id'] ?? null) || ! hash_equals($this->client->clientId(), $grant['client_id'])
            || ! is_int($grant['user_id'] ?? null)) {
            return null;
        }

        return $grant['user_id'];
    }

    /** @param  array<string, string>  $params */
    private function back(array $params): AuthorizeResult
    {
        $uri = $this->client->redirectUri();

        return AuthorizeResult::redirect($uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    private static function randomSecret(): string
    {
        return self::base64url(random_bytes(32));
    }

    private static function s256(string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
