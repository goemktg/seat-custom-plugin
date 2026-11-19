<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\FitMove;
use PHPUnit\Framework\TestCase;

final class FitMoveTest extends TestCase
{
    public function test_only_a_fit_gone_from_the_doctrine_site_can_be_moved_from(): void
    {
        self::assertTrue(FitMove::canMoveFrom(7, 'missing'));
        self::assertFalse(FitMove::canMoveFrom(7, null), 'still synced: the site keeps it');
        self::assertFalse(FitMove::canMoveFrom(null, null), 'a hand-made fit');
        self::assertFalse(FitMove::canMoveFrom(null, 'missing'));
    }

    public function test_a_staging_where_both_fits_have_a_required_count_blocks_the_move(): void
    {
        self::assertSame([3], FitMove::clashes([3 => 40, 5 => 10], [3 => 25, 9 => 2]));
        self::assertSame([], FitMove::clashes([3 => 40], [5 => 1]));
        self::assertSame([], FitMove::clashes([3 => 40], [3 => 0]), 'a zero count is no count');
        self::assertSame([], FitMove::clashes([3 => 0], [3 => 7]), 'nothing to move there');
        self::assertSame([3, 5], FitMove::clashes([5 => 1, 3 => 1], [3 => 1, 5 => 1]), 'sorted');
    }

    public function test_the_summary_names_both_fits_and_what_moved(): void
    {
        self::assertSame(
            'Moved required counts at 2 stagings and 15 stock entries from "Rail Ferox" to "Rail Ferox v2".',
            FitMove::summary('Rail Ferox', 'Rail Ferox v2', 2, 15),
        );
        self::assertSame(
            'Moved required counts at 1 staging and 1 stock entry from "A" to "B".',
            FitMove::summary('A', 'B', 1, 1),
        );
        self::assertSame('Moved nothing from "A" to "B": it had no required counts or stock entries.', FitMove::summary('A', 'B', 0, 0));
    }
}
