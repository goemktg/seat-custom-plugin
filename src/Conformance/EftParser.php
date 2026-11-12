<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

/**
 * Splits EFT text into its slot sections and trailing cargo lines.
 *
 * EFT lists sections separated by blank lines in the order low, mid, high,
 * rig; every later section (drones, fighters, cargo) is a quantity list.
 */
final class EftParser
{
    public const SLOT_SECTIONS = ['low', 'mid', 'high', 'rig'];

    public static function parse(string $eft): ParsedEft
    {
        $lines = preg_split('/\r\n|\r|\n/', $eft) ?: [];
        $shipName = null;
        $fitName = '';
        $sections = [];
        $current = [];

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($shipName === null) {
                if (preg_match('/^\[([^,\]]+)(?:,\s*([^\]]*))?\]$/', $line, $m)) {
                    $shipName = trim($m[1]);
                    $fitName = trim($m[2] ?? '');
                }
                continue;
            }
            if ($line === '') {
                if ($current !== []) {
                    $sections[] = $current;
                    $current = [];
                }
                continue;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $sections[] = $current;
        }

        if ($shipName === null || $shipName === '') {
            throw new \InvalidArgumentException('EFT must start with a [Ship, Fit name] header.');
        }

        $slots = array_fill_keys(self::SLOT_SECTIONS, []);
        foreach (self::SLOT_SECTIONS as $index => $section) {
            foreach ($sections[$index] ?? [] as $line) {
                [$name, $qty] = self::moduleLine($line);
                if ($name !== null) {
                    $slots[$section][$name] = ($slots[$section][$name] ?? 0) + $qty;
                }
            }
        }

        $cargoLines = [];
        foreach (array_slice($sections, count(self::SLOT_SECTIONS)) as $section) {
            foreach ($section as $line) {
                $cargoLines[] = $line;
            }
        }

        return new ParsedEft($shipName, $fitName, $slots, $cargoLines);
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private static function moduleLine(string $line): array
    {
        if ($line === '' || str_starts_with($line, '[')) {
            return [null, 0];
        }
        $module = trim((string) preg_replace('/\s*\/offline\s*$/i', '', trim(explode(',', $line, 2)[0])));
        $qty = 1;
        if (preg_match('/^(.*?)\s+x(\d{1,6})\s*$/i', $module, $m) && trim($m[1]) !== '' && (int) $m[2] > 0) {
            $module = trim($m[1]);
            $qty = (int) $m[2];
        }

        return $module === '' ? [null, 0] : [$module, $qty];
    }
}
