<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Services\DoctrineSync;

/**
 * A sync that could not run. The message is shown to users and stored, so it
 * never carries the token, the address or the upstream body.
 */
final class DoctrineSyncException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $gone = false)
    {
        parent::__construct($message);
    }
}
