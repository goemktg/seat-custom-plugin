<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\EftDiff;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncPlanner;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\UpstreamDoctrine;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\UpstreamFit;
use PHPUnit\Framework\TestCase;

final class DoctrineSyncTest extends TestCase
{
    private const FEROX = "[Ferox, Rail Ferox]\nMagnetic Field Stabilizer II\nMagnetic Field Stabilizer II\n\nLarge Shield Extender II\n\n250mm Railgun II\n\nMedium Core Defense Field Extender I\n\nAntimatter Charge M x2000\n";

    private const SOURCE = 7;

    /** @param list<array<string, mixed>> $fits */
    private static function response(array $fits, array $over = []): array
    {
        return [
            'doctrine' => $over + ['slug' => 'rail-ferox', 'name' => 'Rail Ferox Fleet', 'status' => 'active',
                'roles' => [['name' => 'DPS', 'fits' => $fits]]],
            'version' => 3,
            'updatedAt' => 1,
        ];
    }

    /** @return callable(UpstreamFit): array{shipTypeId: ?int, unresolved: list<string>} */
    private static function resolver(array $unknown = []): callable
    {
        $hulls = ['Ferox' => 16227, 'Scimitar' => 11978, 'Claymore' => 22468];

        return static fn (UpstreamFit $fit): array => [
            'shipTypeId' => $hulls[$fit->shipName] ?? null,
            'unresolved' => array_values(array_filter($unknown, static fn (string $item): bool => str_contains($fit->eft, $item))),
        ];
    }

    /** @param array<string, array<string, mixed>> $rows keyed "shipTypeId:lower name" */
    private static function finder(array $rows): callable
    {
        return static function (int $shipTypeId, string $fitName, string $doctrine) use ($rows): ?array {
            $row = $rows[$shipTypeId . ':' . mb_strtolower($fitName)] ?? null;

            return $row === null ? null : $row + ['eft' => self::FEROX, 'eftHash' => null, 'missing' => false, 'sameHull' => true];
        };
    }

    /** @return array<string, list<SyncAction>> */
    private static function byKind(array $actions): array
    {
        $out = [];
        foreach ($actions as $action) {
            $out[$action->kind][] = $action;
        }

        return $out;
    }

    public function testReadsFitsWithShipAndNameFromTheEftHeader(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([['eft' => self::FEROX, 'ship' => 'x', 'name' => 'y']]));

        $this->assertSame('rail-ferox', $doctrine->slug);
        $this->assertSame('Rail Ferox Fleet', $doctrine->name);
        $this->assertSame(3, $doctrine->version);
        $this->assertCount(1, $doctrine->fits);
        $this->assertSame('Ferox', $doctrine->fits[0]->shipName);
        $this->assertSame('Rail Ferox', $doctrine->fits[0]->fitName);
        $this->assertSame([], $doctrine->unreadable);
    }

    public function testAFitWithoutANameTakesTheDoctrineName(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([['eft' => "[Ferox, ]\nDamage Control II\n"]]));

        $this->assertSame('Rail Ferox Fleet', $doctrine->fits[0]->fitName);
    }

    public function testRefusesAResponseThatIsNotADoctrine(): void
    {
        foreach ([
            null, [], 'text',
            ['doctrine' => [], 'version' => 1],
            self::response([], ['slug' => '../etc']),
            self::response([], ['slug' => 'Has-Upper']),
            self::response([], ['name' => '']),
            self::response([], ['name' => str_repeat('n', 101)]),
            self::response([], ['name' => "bad\x07name"]),
            self::response([], ['status' => 'deleted']),
            self::response([], ['roles' => 'none']),
            ['version' => 0] + self::response([]),
            ['version' => '3'] + self::response([]),
        ] as $payload) {
            try {
                UpstreamDoctrine::fromResponse($payload);
                $this->fail('accepted ' . json_encode($payload));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFitsThatAreNotReadableEftAreListedNotDropped(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([
            ['eft' => 'no header', 'name' => 'Broken', 'ship' => 'Ferox'],
            ['eft' => str_repeat('a', 20001)],
            ['eft' => "[Ferox, Nul]\n\x00"],
            ['share' => '#v1.abc'],
            ['eft' => self::FEROX],
        ]));

        $this->assertCount(1, $doctrine->fits);
        $this->assertSame(['Broken (Ferox)', 'unnamed fit', 'unnamed fit', 'unnamed fit'], $doctrine->unreadable);
    }

    public function testTheHashIgnoresLineEndingsAndTrailingSpace(): void
    {
        $this->assertSame(UpstreamFit::hash("[Ferox, A]\nDamage Control II\n"), UpstreamFit::hash("[Ferox, A]\r\nDamage Control II  \r\n\r\n"));
        $this->assertNotSame(UpstreamFit::hash("[Ferox, A]\nDamage Control II\n"), UpstreamFit::hash("[Ferox, A]\nDamage Control I\n"));
    }

    public function testANewFitIsAdded(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([['eft' => self::FEROX]]));

        $actions = SyncPlanner::plan($doctrine, null, [], self::resolver(), self::finder([]));

        $this->assertCount(1, $actions);
        $this->assertSame(SyncAction::ADD, $actions[0]->kind);
        $this->assertSame(16227, $actions[0]->shipTypeId);
        $this->assertSame('Rail Ferox (Ferox)', $actions[0]->label);
    }

    public function testALinkedFitIsUnchangedUpdatedOrReturned(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([['eft' => self::FEROX]]));
        $hash = UpstreamFit::hash(self::FEROX);
        $old = str_replace('250mm Railgun II', '200mm Railgun II', self::FEROX);
        $row = fn (array $over) => self::finder(['16227:rail ferox' => $over + ['id' => 5, 'sourceId' => self::SOURCE]]);
        $linked = [['id' => 5, 'label' => 'Rail Ferox (Ferox)', 'missing' => false]];

        $same = SyncPlanner::plan($doctrine, self::SOURCE, $linked, self::resolver(), $row(['eftHash' => $hash]));
        $changed = SyncPlanner::plan($doctrine, self::SOURCE, $linked, self::resolver(), $row(['eft' => $old, 'eftHash' => UpstreamFit::hash($old)]));
        $back = SyncPlanner::plan($doctrine, self::SOURCE, $linked, self::resolver(), $row(['eftHash' => $hash, 'missing' => true]));

        $this->assertSame([SyncAction::UNCHANGED], array_column($same, 'kind'));
        $this->assertSame([SyncAction::UPDATE], array_column($changed, 'kind'));
        $this->assertSame(5, $changed[0]->fitId);
        $this->assertSame([SyncAction::RETURNED], array_column($back, 'kind'));
    }

    public function testALinkedFitEditedHereIsHeldNotOverwritten(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([['eft' => self::FEROX]]));
        $edited = str_replace('250mm Railgun II', '200mm Railgun II', self::FEROX);
        $find = self::finder(['16227:rail ferox' => ['id' => 5, 'sourceId' => self::SOURCE, 'eft' => $edited, 'eftHash' => UpstreamFit::hash(self::FEROX)]]);

        $actions = SyncPlanner::plan($doctrine, self::SOURCE, [['id' => 5, 'label' => 'x', 'missing' => false]], self::resolver(), $find);

        $this->assertSame([SyncAction::HELD], array_column($actions, 'kind'));
        $this->assertStringContainsString('changed here', (string) $actions[0]->detail);
    }

    public function testAManualFitWithTheSameNameHoldsTheUpstreamFitUntilLinked(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([['eft' => self::FEROX]]));
        $find = self::finder(['16227:rail ferox' => ['id' => 9, 'sourceId' => null]]);

        $held = SyncPlanner::plan($doctrine, self::SOURCE, [], self::resolver(), $find);
        $linked = SyncPlanner::plan($doctrine, self::SOURCE, [], self::resolver(), $find, [9]);

        $this->assertSame([SyncAction::HELD], array_column($held, 'kind'));
        $this->assertSame(9, $held[0]->fitId);
        $this->assertSame([SyncAction::LINK], array_column($linked, 'kind'));
    }

    public function testAFitOfAnotherFollowedDoctrineCannotBeTakenOver(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([['eft' => self::FEROX]]));
        $find = self::finder(['16227:rail ferox' => ['id' => 9, 'sourceId' => 99]]);

        $actions = SyncPlanner::plan($doctrine, self::SOURCE, [], self::resolver(), $find, [9]);

        $this->assertSame([SyncAction::HELD], array_column($actions, 'kind'));
    }

    public function testASameNameOnAnotherHullInTheDoctrineIsHeld(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([['eft' => self::FEROX]]));
        $find = static fn (): array => ['id' => 4, 'sourceId' => self::SOURCE, 'eft' => '', 'eftHash' => null, 'missing' => false, 'sameHull' => false];

        $actions = SyncPlanner::plan($doctrine, self::SOURCE, [], self::resolver(), $find);

        $this->assertSame([SyncAction::HELD], array_column($actions, 'kind'));
    }

    public function testTwoUpstreamFitsWithOneNameKeepTheFirst(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([
            ['eft' => self::FEROX],
            ['eft' => str_replace('Rail Ferox', 'RAIL FEROX', self::FEROX)],
            ['eft' => "[Scimitar, Rail Ferox]\nDamage Control II\n"],
        ]));

        $actions = SyncPlanner::plan($doctrine, null, [], self::resolver(), self::finder([]));

        $this->assertSame([SyncAction::ADD, SyncAction::HELD, SyncAction::HELD], array_column($actions, 'kind'));
    }

    public function testUnknownHullsAndItemsFail(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([
            ['eft' => "[Nonexistent, A]\nDamage Control II\n"],
            ['eft' => self::FEROX],
        ]));

        $actions = SyncPlanner::plan($doctrine, null, [], self::resolver(['Antimatter Charge M']), self::finder([]));

        $this->assertSame([SyncAction::FAILED, SyncAction::FAILED], array_column($actions, 'kind'));
        $this->assertStringContainsString('not a ship name', (string) $actions[0]->detail);
        $this->assertStringContainsString('Antimatter Charge M', (string) $actions[1]->detail);
    }

    public function testLinkedFitsGoneUpstreamAreMissingOnceAndNeverDeleted(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([]));
        $linked = [
            ['id' => 5, 'label' => 'Old Booster (Claymore)', 'missing' => false],
            ['id' => 6, 'label' => 'Older (Ferox)', 'missing' => true],
        ];

        $actions = SyncPlanner::plan($doctrine, self::SOURCE, $linked, self::resolver(), self::finder([]));

        $this->assertSame([SyncAction::MISSING], array_column($actions, 'kind'));
        $this->assertSame(5, $actions[0]->fitId);
    }

    public function testOnlyAddUpdateLinkAndReturnWriteAFit(): void
    {
        $writes = array_filter(
            [SyncAction::ADD, SyncAction::UPDATE, SyncAction::LINK, SyncAction::RETURNED, SyncAction::UNCHANGED, SyncAction::MISSING, SyncAction::HELD, SyncAction::FAILED],
            static fn (string $kind): bool => (new SyncAction($kind, 'x'))->writes(),
        );

        $this->assertSame([SyncAction::ADD, SyncAction::UPDATE, SyncAction::LINK, SyncAction::RETURNED], array_values($writes));
    }

    public function testTheDiffNamesWhatWasAddedAndRemoved(): void
    {
        $new = str_replace("Magnetic Field Stabilizer II\nMagnetic Field Stabilizer II\n", "Magnetic Field Stabilizer II\nTracking Enhancer II\n", self::FEROX);
        $new = str_replace('x2000', 'x3000', $new);

        $this->assertSame(
            ['removed' => ['Magnetic Field Stabilizer II ×1'], 'added' => ['Antimatter Charge M ×1000', 'Tracking Enhancer II ×1']],
            EftDiff::between(self::FEROX, $new),
        );
        $this->assertSame(['removed' => [], 'added' => []], EftDiff::between(self::FEROX, self::FEROX));
    }
}
