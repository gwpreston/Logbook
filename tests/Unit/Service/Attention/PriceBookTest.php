<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Service\Attention\PriceBook;
use Logbook\Service\Attention\PriceOutlier;
use PHPUnit\Framework\TestCase;

/**
 * The owner's fill-ups for the price check's wider comparison are loaded,
 * sorted and grouped once for a whole *Needs attention* list (Phase 54.5,
 * K149), and judge as the plain list does.
 */
final class PriceBookTest extends TestCase
{
    public function testEachOwnerAndCurrencyIsLoadedAndGroupedOnce(): void
    {
        $book = new PriceBook();
        $loads = 0;
        $load = static function () use (&$loads): array {
            $loads++;

            return [];
        };

        $first = $book->fillUps(1, 'GBP', $load);
        self::assertSame($first, $book->fillUps(1, 'GBP', $load));
        $book->fillUps(1, 'EUR', $load);
        $book->fillUps(2, 'GBP', $load);

        self::assertSame(3, $loads);
    }

    public function testTheGroupedFillUpsJudgeAsThePlainList(): void
    {
        $at = new DateTimeImmutable('2026-01-01 09:00', new DateTimeZone('UTC'));
        $entry = static fn (int $id, int $vehicle, int $day, string $price): FuelEntry => new FuelEntry(
            $id,
            $vehicle,
            new FuelEntryData($at->modify("+$day days"), '10000', Fuel::Petrol, '40.00', $price, '55.60'),
            $at,
            $at,
        );
        // One fill-up on this vehicle, judged against the owner's others.
        $own = [$entry(1, 1, 10, '2.100')];
        $owners = [...$own, $entry(2, 2, 8, '1.400'), $entry(3, 2, 9, '1.410'), $entry(4, 3, 12, '1.390')];

        $plain = PriceOutlier::of($own, static fn (): array => $owners, 35);
        $book = new PriceBook();
        $grouped = PriceOutlier::of($own, static fn () => $book->fillUps(1, 'GBP', static fn (): array => $owners), 35);

        self::assertCount(1, $plain);
        self::assertTrue($plain[0]->wider);
        self::assertEquals($plain, $grouped);
    }
}
