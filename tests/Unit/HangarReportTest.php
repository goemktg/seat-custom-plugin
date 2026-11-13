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

    private static function fit(int $id, int $hull, string $name, array $low, string $doctrine = 'D'): CompiledFit
    {
        return new CompiledFit($id, $doctrine, $name, $hull, $id,
            ['low' => $low, 'mid' => [], 'high' => [], 'rig' => []], [], [], [], [], []);
    }

    private static function ship(int $item, int $hull, ?string $name, array $low): ShipSnapshot
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
            self::ship(11, self::ZIRNITRA, 'Zirni II', [100 => 1]),
            self::ship(12, self::ZIRNITRA, 'Zirni II', []),           // named right, needs fixing
            self::ship(13, self::ARCHON, 'Archon Logi', [200 => 1]),
            self::ship(14, self::NAGLFAR, 'Naglfar', []),             // no fit with this name
            self::ship(15, self::CONTAINER, 'Zirni II', []),          // not a ship, even with a fit's name
            self::ship(16, self::ZIRNITRA, 'Zirni B', [100 => 1]),    // perfect modules but not named after a fit
            self::ship(17, self::ARCHON, 'Zirni II', []),             // fit's name on the wrong hull
            self::ship(18, self::ZIRNITRA, ' Zirni II ', [100 => 1]), // surrounding spaces are ignored
            self::ship(19, self::ZIRNITRA, 'zirni ii', [100 => 1]),   // case must match
            self::ship(20, self::ZIRNITRA, null, [100 => 1]),         // unnamed
        ];
        $isShip = static fn (int $typeId): bool => $typeId !== self::CONTAINER;

        return HangarReport::build($ships, $fits, [1 => 3, 2 => 1], new FitMatcher(), $isShip);
    }

    public function testProblemsListShipsNamedAfterAFitThatMissIt(): void
    {
        $report = $this->report();

        $this->assertSame([12], array_map(static fn ($p) => $p['ship']->itemId, $report->problems));
        $this->assertSame('Zirni II', $report->problems[0]['match']->fit->name);
    }

    public function testShipsNotNamedAfterAFitOfTheirHullAreListedSeparately(): void
    {
        $report = $this->report();

        // ships with a rename hint come first, then by name
        $this->assertSame([19, 20, 14, 16, 17], array_map(static fn ($s) => $s->itemId, $report->unmatched));
    }

    public function testRenameHintOnlyForSameHullFitsDifferingInCaseOrSpacing(): void
    {
        $report = $this->report();

        // 19 "zirni ii" -> "Zirni II"; 17 has the exact name but on another hull; 16 is a different name
        $this->assertSame([19 => 'Zirni II'], $report->suggestions);
    }

    public function testPerFitRowsCountTargetNamedMatchNeedFixShortAndMissing(): void
    {
        $rows = $this->report()->fits;

        $this->assertSame(
            [
                ['fitId' => 1, 'name' => 'Zirni II', 'target' => 3, 'named' => 3, 'match' => 2, 'needFix' => 1, 'short' => 1, 'missing' => 0],
                ['fitId' => 2, 'name' => 'Archon Logi', 'target' => 1, 'named' => 1, 'match' => 1, 'needFix' => 0, 'short' => 0, 'missing' => 0],
                ['fitId' => 3, 'name' => 'Zirni I', 'target' => 0, 'named' => 0, 'match' => 0, 'needFix' => 0, 'short' => 0, 'missing' => 0],
            ],
            array_map(static fn (array $r) => array_diff_key($r, ['fit' => true]), $rows),
        );
    }

    public function testProblemsAreGroupedPerFitInFitOrder(): void
    {
        $fits = [
            self::fit(1, self::ZIRNITRA, 'Zirni II', [100 => 1]),
            self::fit(2, self::ARCHON, 'Archon Logi', [200 => 1]),
        ];
        $ships = [
            self::ship(11, self::ARCHON, 'Archon Logi', []),
            self::ship(12, self::ZIRNITRA, 'Zirni II', []),
            self::ship(13, self::ZIRNITRA, 'Zirni II', [100 => 1]),
            self::ship(14, self::ZIRNITRA, 'Zirni II', []),
        ];

        $groups = HangarReport::build($ships, $fits, [], new FitMatcher(), static fn () => true)->problemGroups();

        $this->assertSame(
            [[1, 3, [12, 14]], [2, 1, [11]]],
            array_map(static fn (array $g) => [$g['fit']->id, $g['named'], array_map(static fn ($p) => $p['ship']->itemId, $g['problems'])], $groups),
        );
    }

    public function testCounts(): void
    {
        $report = $this->report();

        $this->assertSame(3, $report->conformantCount);
        $this->assertSame(1, $report->shortFitCount());
    }

    public function testShortCountsBothShipsToAcquireAndShipsToFix(): void
    {
        $fits = [self::fit(1, self::ZIRNITRA, 'Zirni II', [100 => 1])];
        $ships = [
            self::ship(11, self::ZIRNITRA, 'Zirni II', [100 => 1]),
            self::ship(12, self::ZIRNITRA, 'Zirni II', []),
        ];

        $row = HangarReport::build($ships, $fits, [1 => 5], new FitMatcher(), static fn () => true)->fits[0];

        $this->assertSame(['target' => 5, 'named' => 2, 'match' => 1, 'needFix' => 1, 'short' => 4, 'missing' => 3],
            array_intersect_key($row, array_flip(['target', 'named', 'match', 'needFix', 'short', 'missing'])));
    }

    public function testSameNameInTwoDoctrinesPicksTheClosestFit(): void
    {
        $fits = [
            self::fit(1, self::ARCHON, 'Logi', [200 => 1], 'Armor'),
            self::fit(2, self::ARCHON, 'Logi', [201 => 1], 'Kiting'),
        ];
        $ships = [self::ship(11, self::ARCHON, 'Logi', [201 => 1])];

        $report = HangarReport::build($ships, $fits, [], new FitMatcher(), static fn () => true);

        $this->assertSame([], $report->problems);
        $this->assertSame(1, $report->conformantCount);
        $this->assertSame(0, $report->fits[0]['match']);
        $this->assertSame(1, $report->fits[1]['match']);
    }

    public function testFitNamesAreTrimmedBeforeMatching(): void
    {
        $fits = [self::fit(1, self::ARCHON, ' Logi ', [200 => 1])];
        $ships = [self::ship(11, self::ARCHON, 'Logi', [200 => 1])];

        $report = HangarReport::build($ships, $fits, [], new FitMatcher(), static fn () => true);

        $this->assertSame(1, $report->fits[0]['match']);
        $this->assertSame([], $report->unmatched);
    }

    public function testUnicodeSpacesAroundANameAreIgnoredButInnerSpacingIsNot(): void
    {
        $fits = [self::fit(1, self::ARCHON, 'Logi A', [200 => 1])];
        $ships = [
            self::ship(11, self::ARCHON, "Logi A\u{00A0}", [200 => 1]), // trailing no-break space
            self::ship(12, self::ARCHON, "Logi\u{00A0}A", [200 => 1]),  // different inner space
            self::ship(13, self::ARCHON, "Logi  A", [200 => 1]),        // double inner space
        ];

        $report = HangarReport::build($ships, $fits, [], new FitMatcher(), static fn () => true);

        $this->assertSame(1, $report->fits[0]['match']);
        // unmatched is sorted by name: an ASCII space sorts before U+00A0
        $this->assertSame([13, 12], array_map(static fn ($s) => $s->itemId, $report->unmatched));
        // inner spacing differences still get a rename hint
        $this->assertSame([12 => 'Logi A', 13 => 'Logi A'], $report->suggestions);
    }

    public function testUnmatchedOrderDoesNotDependOnInputOrderWithNumericNames(): void
    {
        $ships = [
            self::ship(1, self::ARCHON, '1a', []),
            self::ship(2, self::ARCHON, '9', []),
            self::ship(3, self::ARCHON, '10', []),
            self::ship(4, self::ARCHON, '1e1', []),
        ];
        $order = static fn (array $input): array => array_map(
            static fn ($s) => $s->itemId,
            HangarReport::build($input, [], [], new FitMatcher(), static fn () => true)->unmatched,
        );

        // byte order: "10" < "1a" < "1e1" < "9", whatever order the ships arrive in
        $this->assertSame([3, 1, 4, 2], $order($ships));
        $this->assertSame([3, 1, 4, 2], $order(array_reverse($ships)));
    }

    public function testNumericAndWhitespaceOnlyNames(): void
    {
        $fits = [self::fit(1, self::ARCHON, '0', [200 => 1]), self::fit(2, self::ARCHON, '123', [201 => 1])];
        $ships = [
            self::ship(11, self::ARCHON, '0', [200 => 1]),
            self::ship(12, self::ARCHON, '123', [201 => 1]),
            self::ship(13, self::ARCHON, '   ', [200 => 1]),
        ];

        $report = HangarReport::build($ships, $fits, [], new FitMatcher(), static fn () => true);

        $this->assertSame(1, $report->fits[0]['match']);
        $this->assertSame(1, $report->fits[1]['match']);
        $this->assertSame([13], array_map(static fn ($s) => $s->itemId, $report->unmatched));
    }
}
