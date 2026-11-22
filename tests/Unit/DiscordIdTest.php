<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\DiscordId;
use PHPUnit\Framework\TestCase;

final class DiscordIdTest extends TestCase
{
    public function testAcceptsOneToTwentyDigits(): void
    {
        $this->assertSame('1', DiscordId::parse('1'));
        $this->assertSame('123456789012345678', DiscordId::parse('123456789012345678'));
        $this->assertSame('12345678901234567890', DiscordId::parse('12345678901234567890'));
    }

    public function testRejectsEmptyAndTooLong(): void
    {
        $this->assertNull(DiscordId::parse(''));
        $this->assertNull(DiscordId::parse('123456789012345678901'));
    }

    public function testRejectsNonDigits(): void
    {
        foreach (['-1', '+1', '1.0', '1e5', ' 1', '1 ', "1\n", '0x1f', '١٢٣', '１２３', "12\u{0000}3"] as $value) {
            $this->assertNull(DiscordId::parse($value), var_export($value, true));
        }
    }

    public function testRejectsAnythingButAString(): void
    {
        $this->assertNull(DiscordId::parse(null));
        $this->assertNull(DiscordId::parse(['1']));
        $this->assertNull(DiscordId::parse(123));
    }
}
