<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Report;

use Logbook\Service\Expense\CostLedger;
use Logbook\Support\Cache\RequestReads;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * A page's widgets share one cost ledger (spec.md §8 *Page budgets*, Phase
 * 54.5, K149): built once per request for a user and vehicles, built again
 * after a write to any table it reads, and on every call outside a request.
 */
final class CostLedgerReadsTest extends AppTestCase
{
    use CostFixtures;

    public function testAPageBuildsTheLedgerOnceUntilACostIsWritten(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->fillUp($app, $golf, '2026-01-01T08:00:00Z', '10000', '40', '60');
        $this->expense($app, $polo, '2026-01-05', '12.50');
        $ledger = $this->service($app, CostLedger::class);
        $reads = $this->service($app, RequestReads::class);

        $reads->begin();
        $both = $ledger->items($owner, [$golf, $polo]);
        self::assertSame($both, $ledger->items($owner, [$golf, $polo]), 'built once');
        self::assertCount(1, $ledger->items($owner, [$golf]), 'other vehicles: their own ledger');
        self::assertCount(2, $both);

        $this->maintenance($app, $golf, '2026-02-01', 'Service', '150');
        $after = $ledger->items($owner, [$golf, $polo]);
        $this->expense($app, $polo, '2026-02-05', '3.00');
        $later = $ledger->items($owner, [$golf, $polo]);
        $reads->end();

        self::assertCount(3, $after, 'a service written: built again');
        self::assertCount(4, $later, 'an expense written: built again');
        self::assertNotSame($later, $ledger->items($owner, [$golf, $polo]), 'outside a request every call builds it');
        self::assertEquals($later, $ledger->items($owner, [$golf, $polo]), 'to the same lines');
    }
}
