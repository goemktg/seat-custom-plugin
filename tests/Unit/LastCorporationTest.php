<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\LastCorporation;
use PHPUnit\Framework\TestCase;

final class LastCorporationTest extends TestCase
{
    private const ALLOWED = [98000001, 98000002];

    public function testRememberedCorporationTheUserMayOpenIsPicked(): void
    {
        $this->assertSame(98000002, LastCorporation::pick(self::ALLOWED, '98000002'));
    }

    public function testAllowedIdsReturnedAsStringsStillMatch(): void
    {
        $this->assertSame(98000001, LastCorporation::pick(['98000001', '98000002'], '98000001'));
    }

    public function testCorporationTheUserLostAccessToIsIgnored(): void
    {
        $this->assertNull(LastCorporation::pick(self::ALLOWED, '98000003'));
        $this->assertNull(LastCorporation::pick([], '98000001'));
    }

    public function testMissingOrMalformedValuesAreIgnored(): void
    {
        foreach ([null, '', ' 98000001', '98000001abc', '-98000001', '9.8e7', '0x5D75E01'] as $value) {
            $this->assertNull(LastCorporation::pick(self::ALLOWED, $value), var_export($value, true));
        }
    }

    public function testNonStringCookieValuesAreIgnored(): void
    {
        $this->assertNull(LastCorporation::pick(self::ALLOWED, ['98000001']));
        $this->assertNull(LastCorporation::pick(self::ALLOWED, 98000001.0));
    }
}
