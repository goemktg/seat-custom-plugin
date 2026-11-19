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

            return $row === null ? null : $row + ['eft' => self::FEROX, 'eftHash' => null, 'missing' => false, 'sameHull' => true,
                'sharedId' => null, 'ownerFollowing' => true, 'ownerName' => 'Shield Fleet'];
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

    public function testAFitWithUnknownItemsKeepsItsLinkedCopy(): void
    {
        // e.g. an item newer than SeAT's item data: the local fit must not turn "gone" and unlock
        $doctrine = UpstreamDoctrine::fromResponse(self::response([['eft' => self::FEROX]]));
        $linked = [['id' => 5, 'label' => 'Rail Ferox (Ferox)', 'missing' => false]];
        $finder = self::finder(['16227:rail ferox' => ['id' => 5, 'sourceId' => self::SOURCE]]);

        $kinds = self::byKind(SyncPlanner::plan($doctrine, self::SOURCE, $linked, self::resolver(['Magnetic Field Stabilizer II']), $finder));

        $this->assertCount(1, $kinds[SyncAction::FAILED]);
        $this->assertArrayNotHasKey(SyncAction::MISSING, $kinds);
    }

    public function testNothingIsMarkedGoneWhileSomeUpstreamFitCannotBeMatched(): void
    {
        // an unreadable fit or an unknown hull could be the linked one; wait until the doctrine reads cleanly
        $linked = [['id' => 5, 'label' => 'Rail Ferox (Ferox)', 'missing' => false]];
        $unreadable = UpstreamDoctrine::fromResponse(self::response([['eft' => 'no header', 'name' => 'Rail Ferox', 'ship' => 'Ferox']]));
        $unknownHull = UpstreamDoctrine::fromResponse(self::response([['eft' => "[Nonexistent Hull, Rail Ferox]\nDamage Control II\n"]]));
        $clean = UpstreamDoctrine::fromResponse(self::response([]));

        foreach ([$unreadable, $unknownHull] as $doctrine) {
            $kinds = self::byKind(SyncPlanner::plan($doctrine, self::SOURCE, $linked, self::resolver(), self::finder([])));
            $this->assertArrayNotHasKey(SyncAction::MISSING, $kinds);
        }
        $kinds = self::byKind(SyncPlanner::plan($clean, self::SOURCE, $linked, self::resolver(), self::finder([])));
        $this->assertCount(1, $kinds[SyncAction::MISSING], 'a cleanly read doctrine without the fit still marks it gone');
    }

    public function testAPlannedWriteIsDroppedWhenTheRowChangedMeanwhile(): void
    {
        $fit = new UpstreamFit(self::FEROX, 'Ferox', 'Rail Ferox');
        $hash = UpstreamFit::hash(self::FEROX);
        $row = ['source_id' => self::SOURCE, 'sync_state' => null, 'eft' => self::FEROX, 'source_eft_hash' => $hash, 'ship_type_id' => 16227];
        $update = new SyncAction(SyncAction::UPDATE, 'Rail Ferox (Ferox)', $fit, 16227, 5);
        $link = new SyncAction(SyncAction::LINK, 'Rail Ferox (Ferox)', $fit, 16227, 5);
        $back = new SyncAction(SyncAction::RETURNED, 'Rail Ferox (Ferox)', $fit, 16227, 5);
        $gone = new SyncAction(SyncAction::MISSING, 'Rail Ferox (Ferox)', fitId: 5);

        $this->assertTrue(SyncPlanner::stillValid($update, $row, self::SOURCE));
        $this->assertFalse(SyncPlanner::stillValid($update, ['eft' => 'edited here'] + $row, self::SOURCE), 'EFT edited after the plan');
        $this->assertFalse(SyncPlanner::stillValid($update, ['ship_type_id' => 1] + $row, self::SOURCE), 'hull changed after the plan');
        $this->assertFalse(SyncPlanner::stillValid($update, ['sync_state' => 'missing'] + $row, self::SOURCE));
        $this->assertFalse(SyncPlanner::stillValid($update, ['source_id' => 9] + $row, self::SOURCE));

        $this->assertTrue(SyncPlanner::stillValid($back, ['sync_state' => 'missing'] + $row, self::SOURCE));
        $this->assertFalse(SyncPlanner::stillValid($back, ['sync_state' => 'missing', 'eft' => 'edited while gone'] + $row, self::SOURCE));

        $this->assertTrue(SyncPlanner::stillValid($link, ['source_id' => null, 'source_eft_hash' => null, 'eft' => 'hand made'] + $row, self::SOURCE));
        $this->assertFalse(SyncPlanner::stillValid($link, $row, self::SOURCE), 'already taken by a doctrine');
        $this->assertFalse(SyncPlanner::stillValid($link, ['source_id' => null, 'ship_type_id' => 1] + $row, self::SOURCE));

        $this->assertTrue(SyncPlanner::stillValid($gone, $row, self::SOURCE));
        $this->assertFalse(SyncPlanner::stillValid($gone, ['sync_state' => 'missing'] + $row, self::SOURCE));
        $this->assertFalse(SyncPlanner::stillValid($gone, ['source_id' => null] + $row, self::SOURCE));
    }

    public function test_a_name_changed_upstream_only_in_case_or_spacing_is_taken_exactly(): void
    {
        $fit = new UpstreamFit('[Leshak, Lesh A/M/A]', 'Leshak', 'Lesh A/M/A');
        $row = ['fit_name' => 'Lesh A/M/A', 'doctrine' => 'Leshak RR'];

        // the database matched these case-insensitively; Hangar Check pairs ships by the exact name
        $this->assertSame([], SyncPlanner::renames($row, $fit, 'Leshak RR'));
        $this->assertSame(['fit_name' => 'Lesh A/M/A'], SyncPlanner::renames(['fit_name' => 'lesh a/m/a'] + $row, $fit, 'Leshak RR'));
        $this->assertSame(['doctrine' => 'Leshak RR'], SyncPlanner::renames(['doctrine' => 'Leshak  rr'] + $row, $fit, 'Leshak RR'));
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
            [SyncAction::ADD, SyncAction::UPDATE, SyncAction::LINK, SyncAction::RETURNED, SyncAction::TAKE_OVER, SyncAction::UNCHANGED,
                SyncAction::SHARED, SyncAction::MISSING, SyncAction::HELD, SyncAction::FAILED],
            static fn (string $kind): bool => (new SyncAction($kind, 'x'))->writes(),
        );

        $this->assertSame([SyncAction::ADD, SyncAction::UPDATE, SyncAction::LINK, SyncAction::RETURNED, SyncAction::TAKE_OVER], array_values($writes));
    }

    public function testReadsTheSharedFitIdOnlyWhenItIsAPositiveWholeNumber(): void
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([
            ['eft' => self::FEROX, 'shared' => 42, 'sharedV' => 3],
            ['eft' => str_replace('Rail Ferox', 'A', self::FEROX), 'shared' => '42'],
            ['eft' => str_replace('Rail Ferox', 'B', self::FEROX), 'shared' => 42.0],
            ['eft' => str_replace('Rail Ferox', 'C', self::FEROX), 'shared' => 0],
            ['eft' => str_replace('Rail Ferox', 'D', self::FEROX), 'shared' => -3],
            ['eft' => str_replace('Rail Ferox', 'E', self::FEROX), 'shared' => true],
            ['eft' => str_replace('Rail Ferox', 'F', self::FEROX)],
        ]));

        $this->assertSame([42, null, null, null, null, null, null], array_map(static fn (UpstreamFit $f) => $f->shared, $doctrine->fits));
    }

    public function testReadsTheReorganisedDoctrineSiteAnswerWhereEveryFitIsShared(): void
    {
        $scimi = "[Scimitar, Scimi Logi]\nDamage Control II\n";
        $doctrine = UpstreamDoctrine::fromResponse([
            'doctrine' => ['slug' => 'rail-ferox', 'name' => 'Rail Ferox Fleet', 'status' => 'active', 'roles' => [
                ['role' => 1, 'name' => 'DPS', 'fits' => [
                    ['eft' => self::FEROX, 'ship' => 'Ferox', 'name' => 'Rail Ferox', 'shared' => 7, 'sharedV' => 2, 'note' => 'n', 'doctrineNote' => ''],
                ]],
                ['role' => 4, 'name' => 'Logi', 'fits' => [
                    ['eft' => $scimi, 'ship' => 'Scimitar', 'name' => 'Scimi Logi', 'shared' => 12, 'sharedV' => 1, 'note' => '', 'doctrineNote' => 'Anchor up'],
                ]],
            ]],
            'version' => 9,
            'updatedAt' => 1,
        ]);

        $this->assertSame([7, 12], array_map(static fn (UpstreamFit $f) => $f->shared, $doctrine->fits));
        $this->assertSame([UpstreamFit::normalize(self::FEROX), UpstreamFit::normalize($scimi)], array_map(static fn (UpstreamFit $f) => $f->eft, $doctrine->fits),
            'notes stay outside the EFT, so every copy of a fit hashes the same');
        $this->assertSame([], $doctrine->unreadable);
    }

    /** @return array{0: UpstreamDoctrine, 1: callable} */
    private static function sharedCopy(array $row, ?int $shared = 42): array
    {
        $doctrine = UpstreamDoctrine::fromResponse(self::response([['eft' => self::FEROX] + ($shared === null ? [] : ['shared' => $shared])]));

        return [$doctrine, self::finder(['16227:rail ferox' => $row + [
            'id' => 9, 'sourceId' => 99, 'sharedId' => 42, 'eftHash' => UpstreamFit::hash(self::FEROX),
        ]])];
    }

    public function testACopyOfASharedFitAnotherFollowedDoctrineBroughtInIsSharedNotHeld(): void
    {
        [$doctrine, $find] = self::sharedCopy([]);

        $actions = SyncPlanner::plan($doctrine, self::SOURCE, [], self::resolver(), $find);

        $this->assertSame([SyncAction::SHARED], array_column($actions, 'kind'));
        $this->assertSame(9, $actions[0]->fitId);
        $this->assertFalse($actions[0]->writes());
        $this->assertStringContainsString('Shield Fleet', (string) $actions[0]->detail);
    }

    public function testASharedCopyIsAlsoSharedOnTheFirstPreviewBeforeTheDoctrineIsFollowed(): void
    {
        [$doctrine, $find] = self::sharedCopy([]);

        $this->assertSame([SyncAction::SHARED], array_column(SyncPlanner::plan($doctrine, null, [], self::resolver(), $find), 'kind'));
    }

    public function testASameNameFromAnotherDoctrineThatIsNotTheSameSharedFitIsStillHeld(): void
    {
        [$other] = self::sharedCopy([]);
        [, $find] = self::sharedCopy(['sharedId' => 41]);
        [$plain, $findPlain] = self::sharedCopy([], null);
        $older = str_replace('250mm Railgun II', '200mm Railgun II', self::FEROX);
        [$copy, $unmarkedOther] = self::sharedCopy(['sharedId' => null, 'eft' => $older, 'eftHash' => UpstreamFit::hash($older)]);

        $this->assertSame([SyncAction::HELD], array_column(SyncPlanner::plan($other, self::SOURCE, [], self::resolver(), $find), 'kind'));
        $this->assertSame([SyncAction::HELD], array_column(SyncPlanner::plan($plain, self::SOURCE, [], self::resolver(), $findPlain), 'kind'));
        $this->assertSame([SyncAction::HELD], array_column(SyncPlanner::plan($copy, self::SOURCE, [], self::resolver(), $unmarkedOther), 'kind'),
            'a row without a shared id and with another EFT may be another fit');
    }

    public function testARowSyncedBeforeSharedIdsWereKeptIsTheSameFitWhenItHasTheSameEft(): void
    {
        [$doctrine, $unmarked] = self::sharedCopy(['sharedId' => null]);
        [, $unmarkedGone] = self::sharedCopy(['sharedId' => null, 'ownerFollowing' => false]);

        $this->assertSame([SyncAction::SHARED], array_column(SyncPlanner::plan($doctrine, self::SOURCE, [], self::resolver(), $unmarked), 'kind'));
        $this->assertSame([SyncAction::TAKE_OVER], array_column(SyncPlanner::plan($doctrine, self::SOURCE, [], self::resolver(), $unmarkedGone), 'kind'),
            'its doctrine is no longer followed, so nothing else would ever mark it');
    }

    public function testASharedFitIsTakenOverWhenItsDoctrineIsNoLongerFollowedOrNoLongerHasIt(): void
    {
        [$doctrine, $unfollowed] = self::sharedCopy(['ownerFollowing' => false]);
        [, $gone] = self::sharedCopy(['missing' => true]);

        $a = SyncPlanner::plan($doctrine, self::SOURCE, [], self::resolver(), $unfollowed);
        $b = SyncPlanner::plan($doctrine, self::SOURCE, [], self::resolver(), $gone);

        $this->assertSame([SyncAction::TAKE_OVER], array_column($a, 'kind'));
        $this->assertSame(9, $a[0]->fitId);
        $this->assertTrue($a[0]->writes());
        $this->assertStringContainsString('no longer followed', (string) $a[0]->detail);
        $this->assertSame([SyncAction::TAKE_OVER], array_column($b, 'kind'));
        $this->assertStringContainsString('no longer has', (string) $b[0]->detail);
    }

    public function testASharedFitEditedHereIsHeldInsteadOfTakenOver(): void
    {
        $edited = str_replace('250mm Railgun II', '200mm Railgun II', self::FEROX);
        [$doctrine, $find] = self::sharedCopy(['ownerFollowing' => false, 'eft' => $edited]);

        $actions = SyncPlanner::plan($doctrine, self::SOURCE, [], self::resolver(), $find);

        $this->assertSame([SyncAction::HELD], array_column($actions, 'kind'));
        $this->assertStringContainsString('changed here', (string) $actions[0]->detail);
    }

    public function testATakeOverIsDroppedWhenTheRowChangedMeanwhile(): void
    {
        $fit = new UpstreamFit(self::FEROX, 'Ferox', 'Rail Ferox', 42);
        $take = new SyncAction(SyncAction::TAKE_OVER, 'Rail Ferox (Ferox)', $fit, 16227, 9);
        $row = ['source_id' => 99, 'sync_state' => null, 'eft' => self::FEROX, 'source_eft_hash' => UpstreamFit::hash(self::FEROX),
            'ship_type_id' => 16227, 'source_shared_id' => 42, 'owner_following' => false];

        $this->assertTrue(SyncPlanner::stillValid($take, $row, self::SOURCE));
        $this->assertTrue(SyncPlanner::stillValid($take, ['owner_following' => true, 'sync_state' => 'missing'] + $row, self::SOURCE));
        $this->assertFalse(SyncPlanner::stillValid($take, ['owner_following' => true] + $row, self::SOURCE), 'its doctrine is followed again');
        $this->assertFalse(SyncPlanner::stillValid($take, ['source_id' => self::SOURCE] + $row, self::SOURCE), 'already ours');
        $this->assertFalse(SyncPlanner::stillValid($take, ['source_id' => null] + $row, self::SOURCE), 'became a hand-made fit');
        $this->assertFalse(SyncPlanner::stillValid($take, ['source_shared_id' => 41] + $row, self::SOURCE));
        $this->assertTrue(SyncPlanner::stillValid($take, ['source_shared_id' => null] + $row, self::SOURCE), 'unmarked, same EFT');
        $this->assertFalse(SyncPlanner::stillValid($take, ['source_shared_id' => null, 'source_eft_hash' => null, 'eft' => 'x'] + $row, self::SOURCE));
        $this->assertFalse(SyncPlanner::stillValid($take, ['eft' => 'edited here'] + $row, self::SOURCE));
        $this->assertFalse(SyncPlanner::stillValid($take, ['ship_type_id' => 1] + $row, self::SOURCE));
    }

    public function testTheSharedIdIsKeptOnlyWhenItChanged(): void
    {
        $fit = new UpstreamFit(self::FEROX, 'Ferox', 'Rail Ferox', 42);
        $plain = new UpstreamFit(self::FEROX, 'Ferox', 'Rail Ferox');

        $this->assertSame([], SyncPlanner::sharedChange(['source_shared_id' => 42], $fit));
        $this->assertSame(['source_shared_id' => 42], SyncPlanner::sharedChange(['source_shared_id' => null], $fit));
        $this->assertSame(['source_shared_id' => null], SyncPlanner::sharedChange(['source_shared_id' => '42'], $plain), 'no longer shared upstream');
        $this->assertSame([], SyncPlanner::sharedChange(['source_shared_id' => null], $plain));
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
