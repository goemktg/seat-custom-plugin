<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\OAuth;

/** A live access token: whose it is and which registered application it was issued to. */
final class BearerGrant
{
    public function __construct(private readonly int $userId, private readonly Client $client)
    {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function client(): Client
    {
        return $this->client;
    }
}
