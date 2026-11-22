<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\OAuth;

use Closure;

/**
 * OAuth 2.0 authorization code grant with PKCE (S256 only) for the
 * applications in the ClientRegistry. No consent screen, no refresh tokens,
 * no tables: codes live 60 seconds and access tokens 5 minutes in the
 * GrantStore, keyed by their SHA-256. Each code and token is bound to the
 * application it was issued to.
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
        private readonly ClientRegistry $clients,
        private readonly GrantStore $store,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    public function enabled(): bool
    {
        return $this->clients->enabled();
    }

    /** The registered application with this client_id, or null. */
    public function client(mixed $clientId): ?Client
    {
        return $this->clients->find($clientId);
    }

    /**
     * @param  array<string, mixed>  $query  the authorize request query
     * @param  Closure(Client): bool  $mayUse  whether the signed-in user may sign in to this application
     */
    public function authorize(array $query, int $userId, Closure $mayUse): AuthorizeResult
    {
        $client = $this->clients->find($query['client_id'] ?? null);
        $redirectUri = $query['redirect_uri'] ?? null;
        if ($client === null || ! $client->hasRedirect($redirectUri)) {
            return AuthorizeResult::reject('The application or its return address is not registered with SeAT.');
        }
        /** @var string $redirectUri */

        $state = $query['state'] ?? null;
        if (! is_string($state) || $state === '' || strlen($state) > self::STATE_MAX) {
            return self::back($redirectUri, ['error' => 'invalid_request']);
        }
        $responseType = $query['response_type'] ?? null;
        $challenge = $query['code_challenge'] ?? null;
        if (! is_string($responseType)
            || ($query['code_challenge_method'] ?? null) !== 'S256'
            || ! is_string($challenge) || preg_match(self::SECRET_PATTERN, $challenge) !== 1) {
            return self::back($redirectUri, ['error' => 'invalid_request', 'state' => $state]);
        }
        if ($responseType !== 'code') {
            return self::back($redirectUri, ['error' => 'unsupported_response_type', 'state' => $state]);
        }
        if (! $mayUse($client)) {
            return self::back($redirectUri, ['error' => 'access_denied', 'state' => $state]);
        }

        $code = self::randomSecret();
        $this->store->putCode(self::hash($code), [
            'user_id' => $userId,
            'client_id' => $client->id(),
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'expires_at' => ($this->now)() + self::CODE_SECONDS,
        ], self::CODE_SECONDS);

        return self::back($redirectUri, ['code' => $code, 'state' => $state]);
    }

    /**
     * Client authentication on the token endpoint (RFC 6749 section 2.3.1): HTTP Basic
     * (client_secret_basic) or client_id and client_secret in the form body (client_secret_post),
     * never both.
     *
     * @param  array<string, mixed>  $form  the token request body
     * @param  string|null  $authorization  the Authorization header
     */
    public function authenticateClient(array $form, ?string $authorization): ClientAuthentication
    {
        if ($authorization === null || preg_match('/^Basic(?:\z|\s+)(.*)\z/is', $authorization, $basic) !== 1) {
            $client = $this->clients->authenticate($form['client_id'] ?? null, $form['client_secret'] ?? null);

            return $client === null ? ClientAuthentication::failed(false) : ClientAuthentication::succeeded($client);
        }
        if (array_key_exists('client_secret', $form)) {
            return ClientAuthentication::ambiguous();
        }
        $decoded = base64_decode($basic[1], true);
        if (! is_string($decoded) || ! str_contains($decoded, ':')) {
            return ClientAuthentication::failed(true);
        }
        // both halves are form-encoded before they are joined (RFC 6749 section 2.3.1)
        [$id, $secret] = array_map('urldecode', explode(':', $decoded, 2));
        if (array_key_exists('client_id', $form) && $form['client_id'] !== $id) {
            return ClientAuthentication::ambiguous();
        }
        $client = $this->clients->authenticate($id, $secret);

        return $client === null ? ClientAuthentication::failed(true) : ClientAuthentication::succeeded($client);
    }

    /** @param  array<string, mixed>  $form  the token request body */
    public function token(array $form, ClientAuthentication $authentication): TokenResult
    {
        $client = $authentication->client();
        if ($client === null) {
            return $authentication->failure() ?? TokenResult::error('invalid_client', 401);
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
            || ! is_string($grant['client_id'] ?? null) || ! hash_equals($grant['client_id'], $client->id())
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

    /** The live access token in an "Authorization: Bearer" header, or null. */
    public function bearer(?string $authorization): ?BearerGrant
    {
        if (! $this->enabled() || $authorization === null
            || preg_match('/^Bearer ([A-Za-z0-9_-]{43})\z/i', $authorization, $m) !== 1) {
            return null;
        }
        $grant = $this->store->token(self::hash($m[1]));
        if ($grant === null
            || ! is_int($grant['expires_at'] ?? null) || $grant['expires_at'] <= ($this->now)()
            || ! is_int($grant['user_id'] ?? null)) {
            return null;
        }
        // the application must still be registered: removing it from the file ends its tokens
        $client = $this->clients->find($grant['client_id'] ?? null);

        return $client === null ? null : new BearerGrant($grant['user_id'], $client);
    }

    /** @param  array<string, string>  $params */
    private static function back(string $uri, array $params): AuthorizeResult
    {
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
