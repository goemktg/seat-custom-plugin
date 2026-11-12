<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Conformance\CompiledFit;
use Goemktg\Seat\SeatCustomPlugin\Conformance\FitMatcher;
use Goemktg\Seat\SeatCustomPlugin\Conformance\HangarReport;
use Goemktg\Seat\SeatCustomPlugin\Conformance\ShipSnapshot;
use PHPUnit\Framework\TestCase;

final class HangarReportTest extends TestCase
{
    private const ZIRNITRA = 52907;
    private const ARCHON = 23757;
    private const NAGLFAR = 19722;
    private const CONTAINER = 3467;

    private static function fit(int $id, int $hull, string $name, array $low): CompiledFit
    {
        return new CompiledFit($id, 'D', $name, $hull, $id,
            ['low' => $low, 'mid' => [], 'high' => [], 'rig' => []], [], [], [], [], []);
    }

    private static function ship(int $item, int $hull, string $name, array $low): ShipSnapshot
    {
        return new ShipSnapshot($item, $hull, $name, 1,
            ['low' => $low, 'mid' => [], 'high' => [], 'rig' => []], [], [], []);
    }

    private function report(): HangarReport
    {
        $fits = [
            self::fit(1, self::ZIRNITRA, 'Zirni II', [100 => 1]),
            self::fit(2, self::ARCHON, 'Archon Logi', [200 => 1]),
            self::fit(3, self::ZIRNITRA, 'Zirni I', [101 => 1]),
        ];
        $ships = [
            self::ship(11, self::ZIRNITRA, 'Zirni B', [100 => 1]),
            self::ship(12, self::ZIRNITRA, 'Zirni A', []),            // needs fixing, closest Zirni II
            self::ship(13, self::ARCHON, 'Archon A', [200 => 1]),
            self::ship(14, self::NAGLFAR, 'Naglfar', []),             // ship without a fit
            self::ship(15, self::CONTAINER, 'Spare box', []),         // not a ship at all
        ];
        $isShip = static fn (int $typeId): bool => $typeId !== self::CONTAINER;

        return HangarReport::build($ships, $fits, [1 => 3, 2 => 1], new FitMatcher(), $isShip);
    }

    public function testProblemsListShipsThatMissTheirClosestFit(): void
    {
        $report = $this->report();

        $this->assertCount(1, $report->problems);
        $this->assertSame('Zirni A', $report->problems[0]['ship']->name);
        $this->assertSame('Zirni II', $report->problems[0]['match']->fit->name);
    }

    public function testShipsWithoutAnyFitForTheirHullAreListedSeparately(): void
    {
        $report = $this->report();

        $this->assertSame(['Naglfar'], array_map(static fn ($s) => $s->name, $report->unmatched));
    }

    public function testPerFitRowsCountTargetMatchNeedFixAndShort(): void
    {
        $rows = $this->report()->fits;

        $this->assertSame(
            [
                ['fitId' => 1, 'name' => 'Zirni II', 'target' => 3, 'match' => 1, 'needFix' => 1, 'short' => 2],
                ['fitId' => 2, 'name' => 'Archon Logi', 'target' => 1, 'match' => 1, 'needFix' => 0, 'short' => 0],
                ['fitId' => 3, 'name' => 'Zirni I', 'target' => 0, 'match' => 0, 'needFix' => 0, 'short' => 0],
            ],
            array_map(static fn (array $r) => array_diff_key($r, ['fit' => true]), $rows),
        );
    }

    public function testCounts(): void
    {
        $report = $this->report();

        $this->assertSame(2, $report->conformantCount);
        $this->assertSame(1, $report->shortFitCount());
    }
}
