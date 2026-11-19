<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\FitLock;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\ImportInput;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\ResponseLimit;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SourceStatus;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction;
use PHPUnit\Framework\TestCase;

final class DoctrineImportUiTest extends TestCase
{
    public function test_a_synced_fit_is_locked_until_its_source_is_gone(): void
    {
        self::assertFalse(FitLock::locked(null, null, true), 'a manual fit');
        self::assertTrue(FitLock::locked(7, null, true));
        self::assertFalse(FitLock::locked(7, 'missing', true), 'source gone: editable like a manual fit');
        self::assertFalse(FitLock::locked(7, null, false), 'sync turned off for the corporation: editable');
    }

    public function test_locked_fields_keep_the_stored_values_whatever_the_form_sends(): void
    {
        $sent = ['doctrine' => 'Hacked', 'fit_name' => 'Other', 'eft' => '[Ferox, X]', 'sort_order' => 3, 'targets' => [1 => 4]];
        $stored = ['doctrine' => 'Rail Ferox Fleet', 'fit_name' => 'Rail Ferox', 'eft' => "[Ferox, Rail Ferox]\n"];

        $out = FitLock::enforce($sent, $stored);

        self::assertSame('Rail Ferox Fleet', $out['doctrine']);
        self::assertSame('Rail Ferox', $out['fit_name']);
        self::assertSame("[Ferox, Rail Ferox]\n", $out['eft']);
        self::assertSame(3, $out['sort_order'], 'other fields stay as sent');
        self::assertSame([1 => 4], $out['targets']);
    }

    public function test_only_a_held_hand_made_fit_can_be_linked(): void
    {
        $hand = new SyncAction(SyncAction::HELD, 'Rail Ferox (Ferox)', fitId: 5, detail: SyncAction::HAND_FIT);
        $other = new SyncAction(SyncAction::HELD, 'Rail Ferox (Ferox)', fitId: 5, detail: 'The EFT was changed here since the last sync.');
        $noId = new SyncAction(SyncAction::HELD, 'Rail Ferox (Ferox)', detail: SyncAction::HAND_FIT);
        $add = new SyncAction(SyncAction::ADD, 'Rail Ferox (Ferox)');

        self::assertTrue($hand->linkable());
        self::assertFalse($other->linkable());
        self::assertFalse($noId->linkable());
        self::assertFalse($add->linkable());
    }

    public function test_corporation_ids_are_positive_unique_and_sorted(): void
    {
        self::assertSame([98000001, 98000002], ImportInput::corporationIds(['98000002', 98000001, '98000002', 'x', -3, 0, null, '1.5']));
        self::assertSame([], ImportInput::corporationIds('nope'));
        self::assertSame([], ImportInput::corporationIds(null));
    }

    public function test_slugs_are_valid_unique_and_capped(): void
    {
        self::assertSame(['rail-ferox', 'logi'], ImportInput::slugs(['rail-ferox', 'logi', 'rail-ferox', '../etc', 'UPPER', '', 5]));
        self::assertSame([], ImportInput::slugs('rail-ferox'));
        $many = array_map(static fn (int $i) => 'd' . $i, range(1, 40));
        self::assertCount(ImportInput::MAX_DOCTRINES, ImportInput::slugs($many));
    }

    public function test_a_response_is_read_only_up_to_the_cap(): void
    {
        $chunks = static function (array $parts): array {
            $i = 0;

            return [static function () use (&$i, $parts): string { return $parts[$i++] ?? ''; }, static function () use (&$i, $parts): bool { return $i >= count($parts); }];
        };

        [$read, $eof] = $chunks(['{"a":', '1}']);
        self::assertSame('{"a":1}', ResponseLimit::read($read, $eof, '', 10, static fn (): bool => false));

        [$read, $eof] = $chunks(['12345', '67890', 'never read']);
        $reads = 0;
        $counting = static function () use ($read, &$reads): string { $reads++; return $read(); };
        $open = static fn (): bool => false;
        self::assertNull(ResponseLimit::read($counting, $eof, '', 8, $open), 'over the cap');
        self::assertSame(2, $reads, 'stops reading as soon as the cap is passed');

        [$read, $eof] = $chunks(['x']);
        self::assertNull(ResponseLimit::read($read, $eof, '11', 10, $open), 'a declared length over the cap is refused before reading');
        self::assertNull(ResponseLimit::read($read, $eof, 'abc', 10, $open), 'a malformed length is refused');
    }

    public function test_a_stalled_answer_stops_at_the_deadline(): void
    {
        // a socket read that times out returns '' while eof stays false: without a deadline this loops forever
        $reads = 0;
        $stalled = static function () use (&$reads): string { $reads++; return ''; };
        $never = static fn (): bool => false;
        $clock = 0;
        $expired = static function () use (&$clock): bool { return ++$clock > 3; };

        try {
            ResponseLimit::read($stalled, $never, '', 10, $expired);
            self::fail('a stalled read must end');
        } catch (\RuntimeException) {
            self::assertSame(3, $reads);
        }
    }

    public function test_a_body_with_too_many_json_elements_is_refused(): void
    {
        self::assertTrue(ResponseLimit::fewEnoughElements('{"fits":[{"eft":"a, b"}]}', 10));
        self::assertFalse(ResponseLimit::fewEnoughElements('[' . str_repeat('0,', 20) . '0]', 10));
    }

    public function test_an_all_digit_slug_stays_a_string(): void
    {
        self::assertSame(['123', 'rail-ferox'], ImportInput::slugs(['123', 'rail-ferox', '123']));
    }

    public function test_link_ids_are_read_per_doctrine(): void
    {
        $sent = ['rail-ferox' => ['5', '6', 'x', '-1'], 'logi' => '7'];

        self::assertSame([5, 6], ImportInput::linkIds($sent, 'rail-ferox'));
        self::assertSame([], ImportInput::linkIds($sent, 'logi'), 'not a list');
        self::assertSame([], ImportInput::linkIds($sent, 'absent'));
        self::assertSame([], ImportInput::linkIds(null, 'rail-ferox'));
    }

    public function test_source_status_puts_errors_before_held_fits(): void
    {
        self::assertSame(SourceStatus::WAITING, SourceStatus::of(null, null, null));
        self::assertSame(SourceStatus::OK, SourceStatus::of('2026-11-19 06:00:00', null, '{"held":[],"failed":[]}'));
        self::assertSame(SourceStatus::ATTENTION, SourceStatus::of('2026-11-19 06:00:00', null, '{"held":[{"fit":"A","detail":"b"}],"failed":[]}'));
        self::assertSame(SourceStatus::ATTENTION, SourceStatus::of('2026-11-19 06:00:00', null, '{"held":[],"failed":[{"fit":"A","detail":"b"}]}'));
        self::assertSame(SourceStatus::ERROR, SourceStatus::of('2026-11-19 06:00:00', 'Could not reach the doctrine site.', '{"held":[{"fit":"A","detail":"b"}]}'));
        self::assertSame(SourceStatus::OK, SourceStatus::of('2026-11-19 06:00:00', null, 'not json'));
    }

    public function test_a_problem_already_reported_last_run_is_not_reported_again(): void
    {
        $last = '{"held":[{"fit":"A (Ferox)","detail":"x"}],"failed":[{"fit":"B (Ferox)","detail":"Unknown items: Foo."}]}';

        self::assertTrue(SourceStatus::alreadyReported($last, 'failed', 'B (Ferox)', 'Unknown items: Foo.'));
        self::assertTrue(SourceStatus::alreadyReported($last, 'held', 'A (Ferox)', 'x'));
        self::assertFalse(SourceStatus::alreadyReported($last, 'failed', 'B (Ferox)', 'Unknown items: Bar.'), 'a new reason is news');
        self::assertFalse(SourceStatus::alreadyReported($last, 'held', 'B (Ferox)', 'Unknown items: Foo.'), 'kinds are separate');
        self::assertFalse(SourceStatus::alreadyReported(null, 'failed', 'B (Ferox)', 'Unknown items: Foo.'));
        self::assertFalse(SourceStatus::alreadyReported('not json', 'failed', 'B (Ferox)', 'Unknown items: Foo.'));
    }

    public function test_source_status_counts_problem_fits(): void
    {
        self::assertSame(['held' => 1, 'failed' => 2], SourceStatus::problems('{"held":[{"fit":"A"}],"failed":[{"fit":"B"},{"fit":"C"}]}'));
        self::assertSame(['held' => 0, 'failed' => 0], SourceStatus::problems(null));
    }
}
