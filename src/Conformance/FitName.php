<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

/**
 * Decides the stored name of a fit. Hangar Check pairs ships with fits by
 * this exact name, so it is trimmed the same way ship names are.
 */
final class FitName
{
    /**
     * The first candidate that is not blank: the typed name, then the name
     * from the EFT's first line, then the doctrine. "0" is a valid name.
     *
     * @return string '' only when every candidate is blank
     */
    public static function resolve(?string $typed, string $fromEft, string $doctrine): string
    {
        foreach ([$typed, $fromEft, $doctrine] as $candidate) {
            $name = HangarReport::nameKey($candidate);
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }
}
