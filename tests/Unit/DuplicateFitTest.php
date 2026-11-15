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

    public function testTheExactCountIsShownBesideAPossiblyCutIdList(): void
    {
        $report = DuplicateFit::migrationReport([
            (object) ['corporation_id' => 98000001, 'ship_type_id' => 11186, 'fit_name' => 'Scout', 'fits' => 3, 'ids' => '4,9'],
        ]);

        $this->assertStringContainsString('fit ids 4,9 (3 fits)', (string) $report);
    }
}
