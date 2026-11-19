<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync;

/**
 * Keeps a doctrine-site answer from costing more memory than a real doctrine:
 * the body is read up to a byte cap while it arrives, and the number of JSON
 * elements is bounded before decoding (tiny elements cost far more memory
 * decoded than their bytes).
 */
final class ResponseLimit
{
    /**
     * @param  callable(): string  $read  next chunk of the body
     * @param  callable(): bool  $eof
     * @param  string  $contentLength  the Content-Length header, '' when absent
     * @return string|null the body, or null when it is over $max
     */
    public static function read(callable $read, callable $eof, string $contentLength, int $max): ?string
    {
        if ($contentLength !== '' && (! ctype_digit($contentLength) || (int) $contentLength > $max)) {
            return null;
        }
        $body = '';
        while (! $eof()) {
            $body .= $read();
            if (strlen($body) > $max) {
                return null;
            }
        }

        return $body;
    }

    /** Every array element or object member needs a comma, bracket or brace, so their count bounds the elements. */
    public static function fewEnoughElements(string $body, int $max): bool
    {
        return substr_count($body, ',') + substr_count($body, '[') + substr_count($body, '{') <= $max;
    }
}
