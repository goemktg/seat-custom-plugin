<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Conformance\EftParser;
use PHPUnit\Framework\TestCase;

final class EftParserTest extends TestCase
{
    private const ZIRNI = <<<EFT
[Zirnitra, Zirni II]
Centum A-Type Multispectrum Energized Membrane
Centum A-Type Multispectrum Energized Membrane
CONCORD 25000mm Steel Plates

Cap Recharger II
[Empty Med slot]

Ultratidal Entropic Disintegrator I, Occult XL
Cap Booster 3200 x2

Capital Trimark Armor Pump I

Hammerhead II x5

Cap Booster 3200 x78
Nanite Repair Paste 50
EFT;

    public function testReadsShipAndFitName(): void
    {
        $eft = EftParser::parse(self::ZIRNI);

        $this->assertSame('Zirnitra', $eft->shipName);
        $this->assertSame('Zirni II', $eft->fitName);
    }

    public function testSlotSectionsFollowEftOrderLowMidHighRig(): void
    {
        $eft = EftParser::parse(self::ZIRNI);

        $this->assertSame([
            'Centum A-Type Multispectrum Energized Membrane' => 2,
            'CONCORD 25000mm Steel Plates' => 1,
        ], $eft->slots['low']);
        $this->assertSame(['Cap Recharger II' => 1], $eft->slots['mid']);
        // charges after the comma are ignored; "x2" on a module line is a count
        $this->assertSame([
            'Ultratidal Entropic Disintegrator I' => 1,
            'Cap Booster 3200' => 2,
        ], $eft->slots['high']);
        $this->assertSame(['Capital Trimark Armor Pump I' => 1], $eft->slots['rig']);
    }

    public function testSectionsAfterRigsAreCargoLines(): void
    {
        $eft = EftParser::parse(self::ZIRNI);

        $this->assertSame(
            ['Hammerhead II x5', 'Cap Booster 3200 x78', 'Nanite Repair Paste 50'],
            $eft->cargoLines,
        );
    }

    public function testMissingSectionsAreEmpty(): void
    {
        $eft = EftParser::parse("[Archon, Logi]\nCapital Remote Armor Repairer I\n");

        $this->assertSame(['Capital Remote Armor Repairer I' => 1], $eft->slots['low']);
        $this->assertSame([], $eft->slots['mid']);
        $this->assertSame([], $eft->slots['rig']);
        $this->assertSame([], $eft->cargoLines);
    }

    public function testToleratesWindowsLineEndingsAndBlankLinesBeforeHeader(): void
    {
        $eft = EftParser::parse("\r\n  [Archon, Logi]\r\nDamage Control II\r\n\r\nCap Recharger II\r\n");

        $this->assertSame('Archon', $eft->shipName);
        $this->assertSame(['Damage Control II' => 1], $eft->slots['low']);
        $this->assertSame(['Cap Recharger II' => 1], $eft->slots['mid']);
    }

    public function testOfflineMarkerIsIgnored(): void
    {
        $eft = EftParser::parse("[Archon, Logi]\nCapital Remote Armor Repairer I /OFFLINE\n");

        $this->assertSame(['Capital Remote Armor Repairer I' => 1], $eft->slots['low']);
    }

    public function testRejectsTextWithoutHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EftParser::parse("Damage Control II\n");
    }

    public function testAllModuleNamesListsSlotNamesOnce(): void
    {
        $eft = EftParser::parse(self::ZIRNI);

        $this->assertSame([
            'Centum A-Type Multispectrum Energized Membrane',
            'CONCORD 25000mm Steel Plates',
            'Cap Recharger II',
            'Ultratidal Entropic Disintegrator I',
            'Cap Booster 3200',
            'Capital Trimark Armor Pump I',
        ], $eft->slotModuleNames());
    }
}
