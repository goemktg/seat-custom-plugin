<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\StockLedger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StockLedgerTest extends TestCase
{
    public function testPurchaseAddsShips(): void
    {
        $this->assertSame(3, StockLedger::signed('purchase', 3));
    }

    public function testLossRemovesShips(): void
    {
        $this->assertSame(-2, StockLedger::signed('loss', 2));
    }

    public function testAdjustKeepsTheGivenSign(): void
    {
        $this->assertSame(4, StockLedger::signed('adjust', 4));
        $this->assertSame(-5, StockLedger::signed('adjust', -5));
    }

    public function testPurchaseAndLossNeedAtLeastOneShip(): void
    {
        foreach ([['purchase', 0], ['purchase', -1], ['loss', 0], ['loss', -3]] as [$kind, $ships]) {
            try {
                StockLedger::signed($kind, $ships);
                $this->fail("$kind $ships was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAdjustByZeroIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockLedger::signed('adjust', 0);
    }

    public function testUnknownKindIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockLedger::signed('gift', 1);
    }

    public function testShipsAreCapped(): void
    {
        $this->assertSame(StockLedger::MAX_SHIPS, StockLedger::signed('purchase', StockLedger::MAX_SHIPS));
        $this->assertSame(-StockLedger::MAX_SHIPS, StockLedger::signed('adjust', -StockLedger::MAX_SHIPS));
        $this->expectException(InvalidArgumentException::class);
        StockLedger::signed('purchase', StockLedger::MAX_SHIPS + 1);
    }

    public function testOutsideHangarIsWhatTheLedgerHasBeyondTheHangar(): void
    {
        $this->assertSame(2, StockLedger::outsideHangar(6, 4));
        $this->assertSame(0, StockLedger::outsideHangar(4, 4));
        $this->assertSame(0, StockLedger::outsideHangar(3, 5));
        $this->assertSame(0, StockLedger::outsideHangar(-1, 0));
    }

    public function testKindLabels(): void
    {
        $this->assertSame(['purchase' => 'Purchase', 'loss' => 'Loss', 'adjust' => 'Adjust'], StockLedger::KINDS);
    }
}
