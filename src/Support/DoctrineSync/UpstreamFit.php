<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync;

/** One fit of an upstream doctrine, as far as its EFT text tells. */
final class UpstreamFit
{
    /**
     * @param  ?int  $shared  the doctrine site's shared fit this is a copy of; copies of one
     *                        shared fit have the same hull, name and EFT in every doctrine
     */
    public function __construct(
        public readonly string $eft,
        public readonly string $shipName,
        public readonly string $fitName,
        public readonly ?int $shared = null,
    ) {
    }

    /** Identifies the EFT text a linked fit was last given, whatever the line endings. */
    public static function hash(string $eft): string
    {
        return hash('sha256', self::normalize($eft));
    }

    public static function normalize(string $eft): string
    {
        $lines = array_map(static fn (string $l): string => rtrim($l), preg_split('/\r\n|\r|\n/', $eft) ?: []);

        return trim(implode("\n", $lines)) . "\n";
    }

    public function label(): string
    {
        return sprintf('%s (%s)', $this->fitName, $this->shipName);
    }
}
