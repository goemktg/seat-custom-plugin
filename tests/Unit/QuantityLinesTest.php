<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Conformance\QuantityLines;
use PHPUnit\Framework\TestCase;

final class QuantityLinesTest extends TestCase
{
    /** Fake SDE: lower-cased name => [typeId, canonical name] */
    private static function lookup(): \Closure
    {
        $types = [
            'cap booster 3200' => [41489, 'Cap Booster 3200'],
            'helium isotopes' => [16274, 'Helium Isotopes'],
            'nanite repair paste' => [28668, 'Nanite Repair Paste'],
            'hammerhead ii' => [2185, 'Hammerhead II'],
            '500mn quad lif restrained microwarpdrive' => [35662, '500MN Quad LiF Restrained Microwarpdrive'],
        ];

        return static function (array $names) use ($types): array {
            $out = [];
            foreach ($names as $name) {
                $key = mb_strtolower($name);
                if (isset($types[$key])) {
                    $out[$key] = ['id' => $types[$key][0], 'name' => $types[$key][1]];
                }
            }
            return $out;
        };
    }

    public function testExplicitXSuffixAndTrailingNumber(): void
    {
        $result = QuantityLines::resolve([
            'Cap Booster 3200 x78',
            'Helium Isotopes 141666',
            'nanite repair paste x10',
        ], self::lookup());

        $this->assertSame([41489 => 78, 16274 => 141666, 28668 => 10], $result->counts);
        $this->assertSame('Nanite Repair Paste', $result->names[28668]);
        $this->assertSame([], $result->unresolved);
    }

    public function testNameEndingInDigitsIsTriedWholeFirst(): void
    {
        // "Cap Booster 3200" alone is one item, not "Cap Booster" x3200
        $result = QuantityLines::resolve(['Cap Booster 3200'], self::lookup());

        $this->assertSame([41489 => 1], $result->counts);
    }

    public function testDuplicateLinesAreSummed(): void
    {
        $result = QuantityLines::resolve(['Hammerhead II x5', 'Hammerhead II x3'], self::lookup());

        $this->assertSame([2185 => 8], $result->counts);
    }

    public function testUnknownLinesAreReportedAndSkipped(): void
    {
        $result = QuantityLines::resolve(['Not An Item x4', '', '  ', '[Empty High slot]', 'Hammerhead II'], self::lookup());

        $this->assertSame([2185 => 1], $result->counts);
        $this->assertSame(['Not An Item x4'], $result->unresolved);
    }

    public function testZeroAndAbsurdQuantitiesAreRejected(): void
    {
        $result = QuantityLines::resolve(['Hammerhead II x0', 'Hammerhead II x99999999999999999999'], self::lookup());

        $this->assertSame([], $result->counts);
        $this->assertSame(['Hammerhead II x0', 'Hammerhead II x99999999999999999999'], $result->unresolved);
    }

    public function testNormalisesInvisibleCharactersAndSpacing(): void
    {
        $result = QuantityLines::resolve(["\u{FEFF}500MN  Quad LiF\u{00A0}Restrained Microwarpdrive\u{200B} 1"], self::lookup());

        $this->assertSame([35662 => 1], $result->counts);
    }
}
