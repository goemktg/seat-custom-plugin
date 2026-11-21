<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\OAuth;

use JsonException;
use Throwable;

/**
 * The registered OAuth applications, read from one JSON file:
 *
 *     {"clients": [{"client_id": "intel-archive", "name": "Intel Archive",
 *                   "secret_sha256": "<64 lower-case hex>",
 *                   "redirect_uris": ["https://intel.nisuwaz.com/auth/callback"],
 *                   "allowed_role_ids": []}]}
 *
 * The file holds only the SHA-256 of each secret. If the file is missing,
 * unreadable, larger than 64 KiB or not valid JSON, has an unknown key, or any
 * one application breaks a rule, the whole registry is off and matches nothing.
 * problem() then says why, naming only the path-free kind of failure and the
 * position of the application, never a value from the file.
 */
final class ClientRegistry
{
    public const MAX_FILE_BYTES = 65536;

    private const CLIENT_ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}\z/';
    private const SHA256_PATTERN = '/^[0-9a-f]{64}\z/';
    private const REQUIRED_KEYS = ['client_id', 'name', 'secret_sha256', 'redirect_uris'];
    private const OPTIONAL_KEYS = ['allowed_role_ids'];

    /** @param  array<array-key, Client>  $clients  by client_id */
    private function __construct(private readonly array $clients, private readonly ?string $problem = null)
    {
    }

    public static function fromFile(?string $path): self
    {
        if ($path === null || $path === '') {
            return self::off('no clients file configured');
        }
        try {
            $json = is_file($path) && is_readable($path)
                ? file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1)
                : false;
        } catch (Throwable) {
            // Laravel turns the read warning into an exception when the file goes away mid-read
            $json = false;
        }
        if (! is_string($json)) {
            return self::off('the file is missing or unreadable');
        }
        if (strlen($json) > self::MAX_FILE_BYTES) {
            return self::off('the file is larger than 64 KiB');
        }
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::off('the file is not valid JSON');
        }
        // json_decode keeps the last of a repeated key, which could silently drop a restriction
        if (self::repeatsAKey($json)) {
            return self::off('the file repeats a key inside one object');
        }

        return self::fromArray($decoded);
    }

    public static function fromArray(mixed $decoded): self
    {
        if (! is_array($decoded) || array_keys($decoded) !== ['clients']
            || ! is_array($decoded['clients']) || $decoded['clients'] === [] || ! array_is_list($decoded['clients'])) {
            return self::off('the file must hold exactly one key, "clients", with a non-empty list');
        }
        $clients = [];
        foreach ($decoded['clients'] as $position => $entry) {
            $client = self::client($entry);
            if ($client === null) {
                return self::off("client {$position} is invalid");
            }
            if (isset($clients[$client->id()])) {
                return self::off("client {$position} repeats a client_id");
            }
            $clients[$client->id()] = $client;
        }

        return new self($clients);
    }

    /** Why the registry is off, or null when it is on. */
    public function problem(): ?string
    {
        return $this->problem;
    }

    public function enabled(): bool
    {
        return $this->clients !== [];
    }

    public function find(mixed $clientId): ?Client
    {
        return is_string($clientId) ? ($this->clients[$clientId] ?? null) : null;
    }

    /** The application these credentials belong to, or null. */
    public function authenticate(mixed $clientId, mixed $clientSecret): ?Client
    {
        if (! is_string($clientSecret)) {
            return null;
        }
        $client = $this->find($clientId);
        // hash and compare even for an unknown client, so the timing does not tell which part was wrong
        $matches = hash_equals($client?->secretSha256() ?? str_repeat('0', 64), hash('sha256', $clientSecret));

        return $matches ? $client : null;
    }

    /**
     * Whether any object in this (already valid) JSON text has the same key twice.
     * Keys are compared after unescaping, so "name" and "n\u0061me" are the same key.
     */
    private static function repeatsAKey(string $json): bool
    {
        /** @var list<array<array-key, true>|null> $open  seen keys per open object, null for an array */
        $open = [];
        $expectKey = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($char === '{') {
                $open[] = [];
                $expectKey = true;
            } elseif ($char === '[') {
                $open[] = null;
                $expectKey = false;
            } elseif ($char === '}' || $char === ']') {
                array_pop($open);
                $expectKey = false;
            } elseif ($char === ',') {
                $expectKey = $open !== [] && is_array($open[count($open) - 1]);
            } elseif ($char === '"') {
                $end = $i + 1;
                while ($end < $length && $json[$end] !== '"') {
                    $end += $json[$end] === '\\' ? 2 : 1;
                }
                if ($expectKey) {
                    $key = (string) json_decode(substr($json, $i, $end - $i + 1));
                    $top = count($open) - 1;
                    if (isset($open[$top][$key])) {
                        return true;
                    }
                    $open[$top][$key] = true;
                    $expectKey = false;
                }
                $i = $end;
            }
        }

        return false;
    }

    private static function off(string $problem): self
    {
        return new self([], $problem);
    }

    private static function client(mixed $entry): ?Client
    {
        if (! is_array($entry)
            || array_diff(self::REQUIRED_KEYS, array_keys($entry)) !== []
            || array_diff(array_keys($entry), [...self::REQUIRED_KEYS, ...self::OPTIONAL_KEYS]) !== []) {
            return null;
        }
        ['client_id' => $id, 'name' => $name, 'secret_sha256' => $hash, 'redirect_uris' => $uris] = $entry;
        // a key that is present must hold a list: "allowed_role_ids": null is an error, not "no restriction"
        $roles = array_key_exists('allowed_role_ids', $entry) ? $entry['allowed_role_ids'] : [];
        if (! is_string($id) || preg_match(self::CLIENT_ID_PATTERN, $id) !== 1
            || ! is_string($name) || trim($name) === ''
            || ! is_string($hash) || preg_match(self::SHA256_PATTERN, $hash) !== 1
            || ! is_array($uris) || $uris === [] || ! array_is_list($uris)
            || ! is_array($roles) || ! array_is_list($roles)) {
            return null;
        }
        foreach ($uris as $uri) {
            if (! is_string($uri) || ! self::validRedirect($uri)) {
                return null;
            }
        }
        if (count(array_unique($uris)) !== count($uris)) {
            return null;
        }
        foreach ($roles as $role) {
            if (! is_int($role) || $role < 1) {
                return null;
            }
        }

        return new Client($id, trim($name), $hash, $uris, $roles);
    }

    /** https, or http for localhost only; no fragment, no user information, no spaces, control characters or backslashes. */
    private static function validRedirect(string $uri): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $uri) === 1) {
            return false;
        }
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
