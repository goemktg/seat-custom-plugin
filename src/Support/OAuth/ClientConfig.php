<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\OAuth;

/**
 * The one registered OAuth client, read from the server environment.
 *
 * With any value empty, a secret shorter than 32 characters, or a redirect
 * address that is not https (http is allowed for localhost only), the client
 * is off and nothing matches it.
 */
final class ClientConfig
{
    private function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {
    }

    /** @param  array<string, mixed>  $config  client_id, client_secret, redirect_uri */
    public static function fromArray(array $config): self
    {
        $read = static fn (string $key): string => is_string($config[$key] ?? null) ? trim($config[$key]) : '';

        return new self($read('client_id'), $read('client_secret'), $read('redirect_uri'));
    }

    public const SECRET_MIN_LENGTH = 32;

    public function enabled(): bool
    {
        return $this->clientId !== ''
            && strlen($this->clientSecret) >= self::SECRET_MIN_LENGTH
            && self::validRedirect($this->redirectUri);
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    /** True when both values are exactly the registered ones. */
    public function matches(mixed $clientId, mixed $redirectUri): bool
    {
        return $this->enabled()
            && is_string($clientId) && hash_equals($this->clientId, $clientId)
            && is_string($redirectUri) && hash_equals($this->redirectUri, $redirectUri);
    }

    public function authenticates(mixed $clientId, mixed $clientSecret): bool
    {
        if (! $this->enabled() || ! is_string($clientId) || ! is_string($clientSecret)) {
            return false;
        }
        // compare fixed-length digests of both, so neither the length nor which one is wrong shows in the timing
        $idOk = hash_equals(hash('sha256', $this->clientId), hash('sha256', $clientId));
        $secretOk = hash_equals(hash('sha256', $this->clientSecret), hash('sha256', $clientSecret));

        return $idOk && $secretOk;
    }

    private static function validRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === ''
            || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || str_contains($uri, '#')) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);

        return $scheme === 'https'
            || ($scheme === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true));
    }
}
