<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\History;

use Logbook\Service\History\ActivityKind;
use Logbook\Service\History\HistoryChip;
use Logbook\Service\History\PrintOptions;
use PHPUnit\Framework\TestCase;

/**
 * The print view's options (spec.md §7.16): costs only with `costs=1`, so
 * the default is the copy for a buyer and no 1.3.0 link shows costs it used
 * to hide.
 */
final class PrintOptionsTest extends TestCase
{
    private const array AVAILABLE = [
        HistoryChip::Service,
        HistoryChip::Fuel,
        HistoryChip::Tyres,
        HistoryChip::Documents,
        HistoryChip::Expenses,
        HistoryChip::Mileage,
    ];

    public function testTheDefaultIsEverythingButFuelWithoutCosts(): void
    {
        $options = PrintOptions::fromQuery([], self::AVAILABLE);

        self::assertFalse($options->costs, 'the copy for a buyer');
        self::assertNotContains(HistoryChip::Fuel, $options->chosen);
        self::assertCount(5, $options->chosen);
        self::assertSame(ActivityKind::Milestone, $options->kinds()[0], 'milestones always');
    }

    public function testOnlyCostsOneShowsCosts(): void
    {
        self::assertTrue(PrintOptions::fromQuery(['costs' => '1'], self::AVAILABLE)->costs);
        self::assertTrue(PrintOptions::fromQuery(['options' => '1', 'costs' => '1'], self::AVAILABLE)->costs);

        foreach ([['costs' => '0'], ['costs' => 'on'], ['costs' => ['1']], ['costs' => '']] as $query) {
            self::assertFalse(PrintOptions::fromQuery($query, self::AVAILABLE)->costs, (string) json_encode($query));
        }
    }

    public function testOldLinksFailSafe(): void
    {
        // 1.3.0: no `options` showed costs by omission; now it hides them.
        self::assertFalse(PrintOptions::fromQuery(['kinds' => ['service']], self::AVAILABLE)->costs);
        // 1.3.0: the form sent without the tick hid costs; it still does.
        self::assertFalse(PrintOptions::fromQuery(['options' => '1', 'kinds' => ['service']], self::AVAILABLE)->costs);
        // 1.3.0: the form sent with the tick showed them; it still does.
        $ticked = ['options' => '1', 'kinds' => ['service'], 'costs' => '1'];
        self::assertTrue(PrintOptions::fromQuery($ticked, self::AVAILABLE)->costs);
    }

    public function testTheSentFormPicksTheKinds(): void
    {
        $options = PrintOptions::fromQuery(['options' => '1', 'kinds' => ['fuel', 'service', 'unknown']], self::AVAILABLE);
        self::assertSame([HistoryChip::Service, HistoryChip::Fuel], $options->chosen);

        self::assertSame([], PrintOptions::fromQuery(['options' => '1'], self::AVAILABLE)->chosen, 'nothing ticked');
        self::assertSame([ActivityKind::Milestone], PrintOptions::fromQuery(['options' => '1'], self::AVAILABLE)->kinds());
    }
}
