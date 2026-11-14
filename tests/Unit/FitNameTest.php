<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Conformance\FitName;
use PHPUnit\Framework\TestCase;

final class FitNameTest extends TestCase
{
    public function testTypedNameWinsAndIsTrimmed(): void
    {
        $this->assertSame('Zirnitra Main', FitName::resolve("  Zirnitra Main\u{00A0}", 'From EFT', 'Siege'));
    }

    public function testZeroIsAName(): void
    {
        $this->assertSame('0', FitName::resolve('0', 'From EFT', 'Siege'));
    }

    public function testFallsBackToTheEftNameThenTheDoctrine(): void
    {
        $this->assertSame('From EFT', FitName::resolve(null, ' From EFT ', 'Siege'));
        $this->assertSame('Siege', FitName::resolve('', '', 'Siege'));
    }

    public function testBlankUnicodeEftNameFallsThroughToTheDoctrine(): void
    {
        $this->assertSame('Siege', FitName::resolve('', "\u{00A0}\u{3000}", 'Siege'));
    }

    public function testAllBlankGivesEmpty(): void
    {
        $this->assertSame('', FitName::resolve(" \u{00A0}", "\u{3000}", "\u{2003}"));
    }
}
