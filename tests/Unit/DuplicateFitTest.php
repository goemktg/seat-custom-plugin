<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\DuplicateFit;
use PHPUnit\Framework\TestCase;

final class DuplicateFitTest extends TestCase
{
    public function testDuplicateOnTheHullKeyExplainsTheHullRule(): void
    {
        $message = DuplicateFit::messageForDriverError(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '98000001-11186-Scout' for key 'custom_plugin_doctrine_fits.cp_doctrine_fits_corp_ship_name_unique'"
        );

        $this->assertSame(DuplicateFit::RACE_SAME_HULL, $message);
    }

    public function testDuplicateOnTheDoctrineKeyExplainsTheDoctrineRule(): void
    {
        $message = DuplicateFit::messageForDriverError(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '98000001-Kiting-Scout' for key 'cp_doctrine_fits_corp_doctrine_name_unique'"
        );

        $this->assertSame(DuplicateFit::RACE_SAME_DOCTRINE, $message);
    }

    public function testOnlyTheKeyClauseDecidesNotTheDuplicateValue(): void
    {
        $message = DuplicateFit::messageForDriverError(
            "1062 Duplicate entry '98000001-Kiting-cp_doctrine_fits_corp_ship_name_unique' for key 'cp_doctrine_fits_corp_doctrine_name_unique'"
        );

        $this->assertSame(DuplicateFit::RACE_SAME_DOCTRINE, $message);
    }

    public function testControlCharactersInStoredNamesCannotForgeReportLines(): void
    {
        $report = (string) DuplicateFit::migrationReport([
            (object) ['corporation_id' => 1, 'ship_type_id' => 2, 'fit_name' => "Scout\n- corporation 9, hull type 9, name \"x\": fit ids 1", 'ids' => '4,9'],
        ]);

        $this->assertSame(3, substr_count($report, "\n") + 1);
        $this->assertStringContainsString('Scout\n- corporation 9', $report);
    }

    public function testUnknownKeyNamesBothRules(): void
    {
        $this->assertSame(DuplicateFit::RACE_EITHER, DuplicateFit::messageForDriverError('1062 Duplicate entry for key PRIMARY'));
        $this->assertSame(DuplicateFit::RACE_EITHER, DuplicateFit::messageForDriverError(''));
    }

    public function testSameHullMessageNamesTheExistingFitAndSaysCaseIsIgnored(): void
    {
        $message = DuplicateFit::sameHullMessage('scout', 'Kiting');

        $this->assertStringContainsString('"scout"', $message);
        $this->assertStringContainsString('"Kiting"', $message);
        $this->assertStringContainsString('upper and lower case', $message);
    }

    public function testNoConflictsGiveNoReport(): void
    {
        $this->assertNull(DuplicateFit::migrationReport([]));
    }

    public function testConflictsAreListedWithTheirIds(): void
    {
        $report = DuplicateFit::migrationReport([
            (object) ['corporation_id' => 98000001, 'ship_type_id' => 11186, 'fit_name' => 'Scout', 'ids' => '4,9'],
            (object) ['corporation_id' => 98000002, 'ship_type_id' => 587, 'fit_name' => 'Tackle', 'ids' => '12,13,20'],
        ]);

        $this->assertNotNull($report);
        $this->assertStringContainsString('corporation 98000001, hull type 11186, name "Scout": fit ids 4,9', $report);
        $this->assertStringContainsString('corporation 98000002, hull type 587, name "Tackle": fit ids 12,13,20', $report);
        $this->assertStringContainsString('Rename or delete', $report);
    }

    public function testNamesHangarCheckTrimsToTheSameKeyConflictOnOneHull(): void
    {
        // older releases trimmed ASCII whitespace only, so these could be stored side by side
        $conflicts = DuplicateFit::nameKeyConflicts([
            (object) ['id' => 4, 'corporation_id' => 98000001, 'ship_type_id' => 11186, 'fit_name' => 'Scout'],
            (object) ['id' => 9, 'corporation_id' => 98000001, 'ship_type_id' => 11186, 'fit_name' => "\u{00A0}Scout"],
            (object) ['id' => 12, 'corporation_id' => 98000001, 'ship_type_id' => 11186, 'fit_name' => "Scout\u{3000}"],
            (object) ['id' => 13, 'corporation_id' => 98000001, 'ship_type_id' => 587, 'fit_name' => "\u{00A0}Scout"],
            (object) ['id' => 14, 'corporation_id' => 98000002, 'ship_type_id' => 11186, 'fit_name' => "\u{00A0}Scout"],
            (object) ['id' => 15, 'corporation_id' => 98000001, 'ship_type_id' => 11186, 'fit_name' => 'Tackle'],
        ]);

        $this->assertCount(1, $conflicts);
        $this->assertSame(98000001, $conflicts[0]->corporation_id);
        $this->assertSame(11186, $conflicts[0]->ship_type_id);
        $this->assertSame('Scout', $conflicts[0]->fit_name);
        $this->assertSame('4,9,12', $conflicts[0]->ids);
        $this->assertSame(3, $conflicts[0]->fits);
    }

    public function testDistinctKeysGiveNoNameKeyConflicts(): void
    {
        $this->assertSame([], DuplicateFit::nameKeyConflicts([
            (object) ['id' => 1, 'corporation_id' => 98000001, 'ship_type_id' => 11186, 'fit_name' => 'Scout'],
            (object) ['id' => 2, 'corporation_id' => 98000001, 'ship_type_id' => 11186, 'fit_name' => 'Scout 2'],
        ]));
    }

    public function testTheExactCountIsShownBesideAPossiblyCutIdList(): void
    {
        $report = DuplicateFit::migrationReport([
            (object) ['corporation_id' => 98000001, 'ship_type_id' => 11186, 'fit_name' => 'Scout', 'fits' => 3, 'ids' => '4,9'],
        ]);

        $this->assertStringContainsString('fit ids 4,9 (3 fits)', (string) $report);
    }
}
