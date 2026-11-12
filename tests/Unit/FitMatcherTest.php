<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Conformance\CompiledFit;
use Goemktg\Seat\SeatCustomPlugin\Conformance\FitMatcher;
use Goemktg\Seat\SeatCustomPlugin\Conformance\ShipSnapshot;
use PHPUnit\Framework\TestCase;

final class FitMatcherTest extends TestCase
{
    private const HULL = 52907;

    private static function fit(int $id, array $slots, array $extra = []): CompiledFit
    {
        return new CompiledFit(
            id: $id,
            doctrine: 'Zirni',
            name: 'Fit ' . $id,
            shipTypeId: self::HULL,
            order: $extra['order'] ?? $id,
            slots: $slots + ['low' => [], 'mid' => [], 'high' => [], 'rig' => []],
            cargo: $extra['cargo'] ?? [],
            container: $extra['container'] ?? [],
            bays: $extra['bays'] ?? [],
            names: [],
            unresolved: $extra['unresolved'] ?? [],
        );
    }

    private static function ship(array $slots, array $extra = []): ShipSnapshot
    {
        return new ShipSnapshot(
            itemId: 1,
            typeId: self::HULL,
            name: 'NIS Zirni 01',
            division: 3,
            slots: $slots + ['low' => [], 'mid' => [], 'high' => [], 'rig' => []],
            cargo: $extra['cargo'] ?? [],
            container: $extra['container'] ?? [],
            bays: $extra['bays'] ?? [],
        );
    }

    public function testExactShipIsConformant(): void
    {
        $result = (new FitMatcher())->best(
            self::ship(['low' => [100 => 2], 'rig' => [400 => 1]]),
            [self::fit(1, ['low' => [100 => 2], 'rig' => [400 => 1]])],
        );

        $this->assertTrue($result->conformant);
        $this->assertSame(0, $result->score);
        $this->assertSame([], $result->missing);
        $this->assertSame([], $result->extra);
    }

    public function testReportsMissingAndExtraPerSection(): void
    {
        $result = (new FitMatcher())->best(
            self::ship(['low' => [100 => 1, 101 => 1], 'mid' => []]),
            [self::fit(1, ['low' => [100 => 2], 'mid' => [200 => 1]])],
        );

        $this->assertFalse($result->conformant);
        $this->assertSame(3, $result->score);
        $this->assertEquals([
            ['section' => 'low', 'typeId' => 100, 'count' => 1],
            ['section' => 'mid', 'typeId' => 200, 'count' => 1],
        ], $result->missing);
        $this->assertEquals([['section' => 'low', 'typeId' => 101, 'count' => 1]], $result->extra);
    }

    public function testSameModuleInWrongSectionIsMissingAndExtra(): void
    {
        $result = (new FitMatcher())->best(
            self::ship(['mid' => [100 => 1]]),
            [self::fit(1, ['low' => [100 => 1]])],
        );

        $this->assertEquals([['section' => 'low', 'typeId' => 100, 'count' => 1]], $result->missing);
        $this->assertEquals([['section' => 'mid', 'typeId' => 100, 'count' => 1]], $result->extra);
    }

    public function testEquivalentVariantsPairOffWithinASection(): void
    {
        $equivalent = static fn (int $a, int $b): bool => [min($a, $b), max($a, $b)] === [18945, 18979];

        $result = (new FitMatcher($equivalent))->best(
            self::ship(['low' => [18979 => 1]]),
            [self::fit(1, ['low' => [18945 => 1]])],
        );

        $this->assertTrue($result->conformant);
    }

    public function testCargoContainerAndBayShortfalls(): void
    {
        $fit = self::fit(1, [], [
            'cargo' => [28668 => 50],
            'container' => [2032 => 4],
            'bays' => ['FuelBay' => [16274 => 141666], 'FleetHangar' => [41489 => 78]],
        ]);
        $ship = self::ship([], [
            'cargo' => [28668 => 60],
            'container' => [2032 => 1],
            'bays' => ['FuelBay' => [16274 => 90000], 'FleetHangar' => [41489 => 78]],
        ]);

        $result = (new FitMatcher())->best($ship, [$fit]);

        $this->assertFalse($result->conformant);
        $this->assertSame([], $result->cargoMissing);
        $this->assertEquals([['section' => 'container', 'typeId' => 2032, 'count' => 3]], $result->containerMissing);
        $this->assertEquals([['section' => 'FuelBay', 'typeId' => 16274, 'count' => 51666]], $result->bayMissing);
        $this->assertSame(3 + 51666, $result->score);
        $this->assertSame(2, $result->itemsToAdd());
        $this->assertSame(0, $result->itemsToRemove());
    }

    public function testPicksFitWithFewestModuleDifferencesBeforeHoldShortfalls(): void
    {
        $close = self::fit(1, ['low' => [100 => 2]], ['bays' => ['FuelBay' => [16274 => 1000]]]);
        $far = self::fit(2, ['low' => [100 => 1, 101 => 1]]);

        $result = (new FitMatcher())->best(self::ship(['low' => [100 => 2]]), [$far, $close]);

        $this->assertSame(1, $result->fit->id);
    }

    public function testIdenticalModulesPreferTheMoreSpecificLoadoutWhenBothFit(): void
    {
        // Zirnitra variants share fitted modules and differ only by bay contents
        $plain = self::fit(1, ['low' => [100 => 1]]);
        $fuelled = self::fit(2, ['low' => [100 => 1]], ['bays' => ['FuelBay' => [16274 => 10]]]);
        $ship = self::ship(['low' => [100 => 1]], ['bays' => ['FuelBay' => [16274 => 10]]]);

        $result = (new FitMatcher())->best($ship, [$plain, $fuelled]);

        $this->assertSame(2, $result->fit->id);
        $this->assertTrue($result->conformant);
    }

    public function testUnresolvedFitLinesMakeTheMatchNonConformant(): void
    {
        $result = (new FitMatcher())->best(
            self::ship(['low' => [100 => 1]]),
            [self::fit(1, ['low' => [100 => 1]], ['unresolved' => ['Typo Module II']])],
        );

        $this->assertFalse($result->conformant);
        $this->assertSame(['Typo Module II'], $result->fit->unresolved);
    }

    public function testNoCandidatesGivesNull(): void
    {
        $this->assertNull((new FitMatcher())->best(self::ship([]), []));
    }
}
