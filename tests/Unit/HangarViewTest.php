<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Conformance\CompiledFit;
use Goemktg\Seat\SeatCustomPlugin\Conformance\FitMatcher;
use Goemktg\Seat\SeatCustomPlugin\Conformance\HangarReport;
use Goemktg\Seat\SeatCustomPlugin\Conformance\MatchResult;
use Goemktg\Seat\SeatCustomPlugin\Conformance\ShipSnapshot;
use PHPUnit\Framework\TestCase;

/** What Hangar Check shows on top of the report: doctrine groups, totals, empty rows. */
final class HangarViewTest extends TestCase
{
    private const LESHAK = 47271;
    private const CERBERUS = 11993;

    private static function fit(int $id, int $hull, string $name, string $doctrine): CompiledFit
    {
        return new CompiledFit($id, $doctrine, $name, $hull, $id,
            ['low' => [100 => 1], 'mid' => [], 'high' => [], 'rig' => []], [], [], [], [], []);
    }

    private static function ship(int $item, int $hull, string $name): ShipSnapshot
    {
        return new ShipSnapshot($item, $hull, $name, 1, ['low' => [], 'mid' => [], 'high' => [], 'rig' => []], [], [], []);
    }

    public function test_problems_are_grouped_by_doctrine_in_fit_order(): void
    {
        $fits = [
            self::fit(1, self::CERBERUS, 'Basi A/M', 'Cerberus'),
            self::fit(2, self::LESHAK, 'Lesh A/M/A', 'Leshak RR'),
            self::fit(3, self::CERBERUS, 'Cerb SM', 'Cerberus'),
        ];
        $ships = [self::ship(10, self::LESHAK, 'Lesh A/M/A'), self::ship(11, self::CERBERUS, 'Basi A/M'),
            self::ship(12, self::LESHAK, 'Lesh A/M/A'), self::ship(13, self::CERBERUS, 'Cerb SM')];
        $report = HangarReport::build($ships, $fits, [], new FitMatcher(), static fn (): bool => true);

        $groups = $report->doctrineGroups();

        self::assertSame(['Cerberus', 'Leshak RR'], array_column($groups, 'doctrine'));
        self::assertSame([2, 2], array_column($groups, 'ships'));
        self::assertSame(['Basi A/M', 'Cerb SM'], array_map(static fn ($g) => $g['fit']->name, $groups[0]['fits']));
    }

    public function test_fits_shared_by_several_followed_doctrines_get_one_tab_of_their_own(): void
    {
        $fits = [
            self::fit(1, self::CERBERUS, 'Basi A/M', 'Cerberus'),
            self::fit(2, self::LESHAK, 'Lesh A/M/A', 'Leshak RR'),
            self::fit(3, self::CERBERUS, 'Cerb SM', 'Cerberus'),
        ];
        $ships = [self::ship(10, self::LESHAK, 'Lesh A/M/A'), self::ship(11, self::CERBERUS, 'Basi A/M'),
            self::ship(12, self::LESHAK, 'Lesh A/M/A'), self::ship(13, self::CERBERUS, 'Cerb SM')];
        $report = HangarReport::build($ships, $fits, [], new FitMatcher(), static fn (): bool => true);

        $groups = $report->doctrineGroups([3, 2]);

        self::assertSame(['Cerberus', HangarReport::SHARED_TAB], array_column($groups, 'doctrine'));
        self::assertSame([false, true], array_column($groups, 'shared'));
        self::assertSame([1, 3], array_column($groups, 'ships'), 'each ship once, in its one tab');
        self::assertSame(['Lesh A/M/A', 'Cerb SM'], array_map(static fn ($g) => $g['fit']->name, $groups[1]['fits']));
    }

    public function test_a_doctrine_named_like_the_shared_tab_stays_its_own_tab(): void
    {
        $fits = [self::fit(1, self::CERBERUS, 'Basi A/M', HangarReport::SHARED_TAB), self::fit(2, self::LESHAK, 'Lesh A/M/A', 'Leshak RR')];
        $ships = [self::ship(10, self::LESHAK, 'Lesh A/M/A'), self::ship(11, self::CERBERUS, 'Basi A/M')];
        $report = HangarReport::build($ships, $fits, [], new FitMatcher(), static fn (): bool => true);

        $groups = $report->doctrineGroups([2]);

        self::assertCount(2, $groups);
        self::assertSame([false, true], array_column($groups, 'shared'));
    }

    public function test_totals_sum_what_to_add_and_remove_across_ships(): void
    {
        $fit = self::fit(1, self::LESHAK, 'Lesh A/M/A', 'Leshak RR');
        $a = new MatchResult($fit, false, 0,
            [['section' => 'low', 'typeId' => 100, 'count' => 2]], [['section' => 'low', 'typeId' => 200, 'count' => 1]],
            [['section' => 'cargo', 'typeId' => 300, 'count' => 248]], [], [['section' => 'FuelBay', 'typeId' => 400, 'count' => 5]],
            refits: [['section' => 'mid', 'typeId' => 900, 'count' => 1]]);
        $b = new MatchResult($fit, false, 0,
            [['section' => 'low', 'typeId' => 100, 'count' => 2]], [], [['section' => 'cargo', 'typeId' => 300, 'count' => 2]],
            [['section' => 'container', 'typeId' => 500, 'count' => 1]], []);

        $totals = HangarReport::totals([['match' => $a], ['match' => $b]]);

        self::assertSame([300 => 250, 400 => 5, 100 => 4, 500 => 1], $totals['add'], 'largest first; accepted refits are not counted');
        self::assertSame([200 => 1], $totals['remove']);
    }

    public function test_a_fit_row_is_empty_with_nothing_required_in_the_hangar_or_ledger(): void
    {
        $row = ['target' => 0, 'named' => 0];

        self::assertTrue(HangarReport::emptyRow($row, null));
        self::assertFalse(HangarReport::emptyRow(['target' => 30] + $row, null));
        self::assertFalse(HangarReport::emptyRow(['named' => 1] + $row, null));
        self::assertFalse(HangarReport::emptyRow($row, 0), 'a ledger with records, even at 0, stays visible');
    }

    public function test_accepted_differences_are_counted_apart_from_what_to_do(): void
    {
        $fit = self::fit(1, self::LESHAK, 'Lesh A/M/A', 'Leshak RR');
        $match = new MatchResult($fit, false, 0, [['section' => 'low', 'typeId' => 100, 'count' => 2]], [], [], [], [],
            refits: [['section' => 'mid', 'typeId' => 900, 'count' => 1], ['section' => 'high', 'typeId' => 901, 'count' => 3]],
            stowed: [['section' => 'low', 'typeId' => 902, 'count' => 1]]);

        self::assertSame(3, $match->acceptedCount());
        self::assertSame(1, $match->itemsToAdd());
    }

    public function test_a_long_doctrine_is_split_into_pages_of_ships(): void
    {
        $fits = [self::fit(1, self::LESHAK, 'Lesh A/M/A', 'Leshak RR'), self::fit(2, self::LESHAK, 'Guard A/M/A', 'Leshak RR')];
        $ships = [];
        for ($i = 0; $i < 7; $i++) {
            $ships[] = self::ship(100 + $i, self::LESHAK, 'Lesh A/M/A');
        }
        for ($i = 0; $i < 4; $i++) {
            $ships[] = self::ship(200 + $i, self::LESHAK, 'Guard A/M/A');
        }
        $report = HangarReport::build($ships, $fits, [], new FitMatcher(), static fn (): bool => true);

        $pages = HangarReport::pages($report->doctrineGroups()[0]['fits'], 5);

        self::assertCount(3, $pages);
        $shape = array_map(static fn (array $page) => array_map(
            static fn (array $g) => [$g['fit']->name, count($g['problems']), $g['continued']], $page), $pages);
        self::assertSame([
            [['Lesh A/M/A', 5, false]],
            [['Lesh A/M/A', 2, true], ['Guard A/M/A', 3, false]],
            [['Guard A/M/A', 1, true]],
        ], $shape);
        self::assertSame([[]], HangarReport::pages([], 5));
    }

    public function test_a_group_heading_names_hull_and_doctrine_once_each(): void
    {
        self::assertSame(['Leshak'], HangarReport::headingParts('Leshak', 'Leshak', 'Lesh A/M/A'));
        self::assertSame(['Cerberus', 'Shield Cerbs'], HangarReport::headingParts('Cerberus', 'Shield Cerbs', 'Basi A/M'));
        self::assertSame(['Cerberus'], HangarReport::headingParts('Cerberus', 'Basi A/M', 'Basi A/M'), 'a doctrine named like the fit is not repeated');
        self::assertSame(['Leshak RR'], HangarReport::headingParts(null, 'Leshak RR', 'Lesh A/M/A'));
    }
}
