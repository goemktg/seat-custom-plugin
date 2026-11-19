<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Services\DoctrineSync;

use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\UpstreamDoctrine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads doctrine.nisuwaz.com with its read-only token. Only the doctrine list
 * and one doctrine are read; nothing is ever sent back.
 */
class DoctrineClient
{
    private const MAX_BYTES = 4_000_000;

    private const MIN_TOKEN = 32;

    public function __construct(private readonly ?string $baseUrl, private readonly ?string $token)
    {
    }

    public static function fromConfig(): self
    {
        $url = config('custom-plugin.doctrine-sync.url');
        $token = config('custom-plugin.doctrine-sync.token');

        return new self(is_string($url) ? trim($url) : null, is_string($token) ? trim($token) : null);
    }

    public function configured(): bool
    {
        return $this->baseUrl !== null && $this->token !== null
            && preg_match('#^https?://[^\s/?\#@]+(?:/[^\s?\#]*)?$#i', $this->baseUrl) === 1
            && strlen($this->token) >= self::MIN_TOKEN;
    }

    /**
     * @return list<array{slug: string, name: string, status: string, version: int, updatedAt: ?int}>
     *
     * @throws DoctrineSyncException
     */
    public function doctrines(): array
    {
        $payload = $this->get('/api/doctrines');
        $list = is_array($payload) ? ($payload['doctrines'] ?? null) : null;
        if (! is_array($list)) {
            throw new DoctrineSyncException('The doctrine site sent an answer this plugin cannot read.');
        }
        $out = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slug = $row['slug'] ?? null;
            $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
            $status = $row['status'] ?? null;
            $version = $row['version'] ?? null;
            if (! is_string($slug) || preg_match(UpstreamDoctrine::SLUG, $slug) !== 1
                || $name === '' || mb_strlen($name) > 100 || preg_match('/\p{Cc}/u', $name) === 1
                || ! in_array($status, UpstreamDoctrine::STATUSES, true) || ! is_int($version) || $version < 1) {
                continue;
            }
            $updatedAt = $row['updatedAt'] ?? null;
            $out[] = ['slug' => $slug, 'name' => $name, 'status' => $status, 'version' => $version,
                'updatedAt' => is_int($updatedAt) ? $updatedAt : null];
        }

        return $out;
    }

    /**
     * @return mixed the decoded `GET /api/doctrines/:slug` response, for UpstreamDoctrine::fromResponse
     *
     * @throws DoctrineSyncException
     */
    public function doctrine(string $slug): mixed
    {
        if (preg_match(UpstreamDoctrine::SLUG, $slug) !== 1) {
            throw new DoctrineSyncException('That is not a doctrine address.');
        }

        return $this->get('/api/doctrines/' . $slug);
    }

    private function get(string $path): mixed
    {
        if (! $this->configured()) {
            throw new DoctrineSyncException('The doctrine site is not set up on this server.');
        }
        try {
            $response = Http::withToken((string) $this->token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                // a redirect would carry the token elsewhere
                ->withOptions(['allow_redirects' => false])
                ->get(rtrim((string) $this->baseUrl, '/') . $path);
        } catch (ConnectionException) {
            throw new DoctrineSyncException('Could not reach the doctrine site.');
        }

        $status = $response->status();
        if ($status !== 200) {
            throw match ($status) {
                401 => new DoctrineSyncException('The doctrine site refused the read token.'),
                404 => new DoctrineSyncException('This doctrine is no longer on the doctrine site.', gone: true),
                429 => new DoctrineSyncException('The doctrine site is refusing this server after failed tokens. Try again in 15 minutes.'),
                default => new DoctrineSyncException(sprintf('The doctrine site answered with HTTP %d.', $status)),
            };
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new DoctrineSyncException('The doctrine site sent an answer that is too large.');
        }
        try {
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DoctrineSyncException('The doctrine site sent an answer this plugin cannot read.');
        }
    }
}
