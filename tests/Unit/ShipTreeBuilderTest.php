<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Conformance\ShipTreeBuilder;
use PHPUnit\Framework\TestCase;

final class ShipTreeBuilderTest extends TestCase
{
    private const OFFICE = 9000;

    private static function row(int $item, int $type, int $location, string $flag, bool $singleton = true, int $qty = 1, ?string $name = null): array
    {
        return [
            'item_id' => $item, 'type_id' => $type, 'location_id' => $location,
            'location_flag' => $flag, 'is_singleton' => $singleton, 'quantity' => $qty, 'name' => $name,
        ];
    }

    public function testFindsAssembledShipsInCorpHangarDivisions(): void
    {
        $ships = ShipTreeBuilder::fromOfficeAssets(self::OFFICE, [
            self::row(1, 52907, self::OFFICE, 'CorpSAG3', true, 1, 'NIS Zirni 04'),
            self::row(2, 52907, self::OFFICE, 'CorpSAG3', false, 2),      // packaged hulls: not ships
            self::row(3, 23757, self::OFFICE, 'CorpDeliveries', true),    // not a hangar division
            self::row(4, 23757, 1, 'HiSlot0', true),                      // fitted module of ship 1
        ]);

        $this->assertCount(1, $ships);
        $this->assertSame(1, $ships[0]->itemId);
        $this->assertSame(52907, $ships[0]->typeId);
        $this->assertSame('NIS Zirni 04', $ships[0]->name);
        $this->assertSame(3, $ships[0]->division);
    }

    public function testGroupsFittedModulesBySlotSection(): void
    {
        [$ship] = ShipTreeBuilder::fromOfficeAssets(self::OFFICE, [
            self::row(1, 52907, self::OFFICE, 'CorpSAG1'),
            self::row(10, 100, 1, 'LoSlot0'),
            self::row(11, 100, 1, 'LoSlot1'),
            self::row(12, 200, 1, 'MedSlot0'),
            self::row(13, 300, 1, 'HiSlot0'),
            self::row(14, 400, 1, 'RigSlot0'),
            self::row(15, 500, 1, 'HiSlot1', false, 50),   // loaded charge stack, not a module
        ]);

        $this->assertSame([100 => 2], $ship->slots['low']);
        $this->assertSame([200 => 1], $ship->slots['mid']);
        $this->assertSame([300 => 1], $ship->slots['high']);
        $this->assertSame([400 => 1], $ship->slots['rig']);
    }

    public function testCountsBaysCargoAndContainerContents(): void
    {
        [$ship] = ShipTreeBuilder::fromOfficeAssets(self::OFFICE, [
            self::row(1, 52907, self::OFFICE, 'CorpSAG1'),
            self::row(20, 16274, 1, 'SpecializedFuelBay', false, 90000),
            self::row(21, 41489, 1, 'FleetHangar', false, 40),
            self::row(22, 28668, 1, 'Cargo', false, 50),
            self::row(23, 3467, 1, 'Cargo', true),                 // a container in cargo
            self::row(24, 2032, 23, 'Unlocked', false, 4),          // inside the container
            self::row(25, 35662, 23, 'Unlocked', true, 0),          // quantity 0 counts as 1
        ]);

        $this->assertSame([16274 => 90000], $ship->bays['FuelBay']);
        $this->assertSame([41489 => 40], $ship->bays['FleetHangar']);
        $this->assertSame([28668 => 50, 3467 => 1, 2032 => 4, 35662 => 1], $ship->cargo);
        $this->assertSame([2032 => 4, 35662 => 1], $ship->container);
    }

    public function testDronesFightersAndSubsystemsCountAsCarriedForEftTrailingSections(): void
    {
        [$ship] = ShipTreeBuilder::fromOfficeAssets(self::OFFICE, [
            self::row(1, 29984, self::OFFICE, 'CorpSAG1'),
            self::row(40, 2185, 1, 'DroneBay', false, 5),
            self::row(41, 40556, 1, 'FighterBay', false, 9),
            self::row(42, 45589, 1, 'SubSystemSlot0'),
        ]);

        $this->assertEquals([2185 => 5, 40556 => 9, 45589 => 1], $ship->cargo);
        $this->assertSame([40556 => 9], $ship->bays['FighterBay']);
    }

    public function testHandlesSeveralOfficesAtOneLocation(): void
    {
        $ships = ShipTreeBuilder::fromOfficeAssets([self::OFFICE, 9001], [
            self::row(1, 52907, self::OFFICE, 'CorpSAG1'),
            self::row(2, 52907, 9001, 'CorpSAG2'),
        ]);

        $this->assertSame([1, 2], array_map(static fn ($s) => $s->itemId, $ships));
    }

    public function testIgnoresItemsOutsideTheShipSubtree(): void
    {
        [$ship] = ShipTreeBuilder::fromOfficeAssets(self::OFFICE, [
            self::row(1, 52907, self::OFFICE, 'CorpSAG1'),
            self::row(30, 100, 777, 'LoSlot0'),   // belongs to some other ship
        ]);

        $this->assertSame([], $ship->slots['low']);
    }
}
