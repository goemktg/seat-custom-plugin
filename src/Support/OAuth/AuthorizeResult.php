<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\OAuth;

/**
 * Either send the browser back to the client, or (when the client or its
 * redirect address is unknown) show an error page and send it nowhere.
 */
final class AuthorizeResult
{
    private function __construct(private readonly ?string $redirectUrl, private readonly string $reason)
    {
    }

    public static function redirect(string $url): self
    {
        return new self($url, '');
    }

    public static function reject(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isRejected(): bool
    {
        return $this->redirectUrl === null;
    }

    public function redirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    /** Why the request was rejected, for the error page. */
    public function reason(): string
    {
        return $this->reason;
    }
}
