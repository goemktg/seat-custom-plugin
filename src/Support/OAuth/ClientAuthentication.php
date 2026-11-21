<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\OAuth;

/** Outcome of checking the credentials on a token request: the application, or the error to answer with. */
final class ClientAuthentication
{
    private const BASIC_CHALLENGE = ['WWW-Authenticate' => 'Basic realm="SeAT"'];

    private function __construct(private readonly ?Client $client, private readonly ?TokenResult $failure)
    {
    }

    public static function succeeded(Client $client): self
    {
        return new self($client, null);
    }

    /** Credentials sent two ways at once, or a form client_id that is not the one in the header. */
    public static function ambiguous(): self
    {
        return new self(null, TokenResult::error('invalid_request'));
    }

    /** RFC 6749 section 5.2: a client that tried HTTP Basic gets a challenge with its 401. */
    public static function failed(bool $viaBasic): self
    {
        return new self(null, TokenResult::error('invalid_client', 401, $viaBasic ? self::BASIC_CHALLENGE : []));
    }

    public function client(): ?Client
    {
        return $this->client;
    }

    public function failure(): ?TokenResult
    {
        return $this->failure;
    }
}
