<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\OAuth;

/** HTTP status and JSON body of a token endpoint answer. */
final class TokenResult
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function __construct(private readonly int $status, private readonly array $body, private readonly array $headers = [])
    {
    }

    public static function issued(string $accessToken, int $expiresIn): self
    {
        return new self(200, ['access_token' => $accessToken, 'token_type' => 'Bearer', 'expires_in' => $expiresIn]);
    }

    /** @param  array<string, string>  $headers */
    public static function error(string $error, int $status = 400, array $headers = []): self
    {
        return new self($status, ['error' => $error], $headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /** @return array<string, string> extra response headers */
    public function headers(): array
    {
        return $this->headers;
    }
}
