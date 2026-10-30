<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Service\Attention\Outlier;
use Logbook\Service\Attention\PriceFinding;
use Logbook\Service\Attention\PriceOutlier;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The price check in integers (Phase 54.5, K149) finds exactly what the
 * check on decimal strings found before it: the same fill-ups, medians,
 * ratios and digit slips, for prices of any number of places, free
 * fill-ups, thresholds of 100% and more, and the owner's wider comparison.
 */
final class PriceOutlierExactTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function runs(): iterable
    {
        foreach ([10, 35, 90, 100, 150] as $percent) {
            foreach ([1, 2, 3] as $seed) {
                yield "$percent%, run $seed" => [$percent, $seed];
            }
        }
    }

    #[DataProvider('runs')]
    public function testTheFindingsAreTheSameAsWithDecimalStrings(int $percent, int $seed): void
    {
        mt_srand($seed);
        $own = self::fills(1, 160, 9);
        $owners = [...$own, ...self::fills(2, 60, 31)];
        $load = static fn (): array => $owners;

        $findings = PriceOutlier::of($own, $load, $percent);

        self::assertEquals(self::reference($own, $owners, $percent), $findings);
        self::assertNotSame([], $findings, 'the run has findings to compare');
    }

    public function testAPriceWithMorePlacesThanStoredIsJudgedOnItsStrings(): void
    {
        $at = new DateTimeImmutable('2026-01-01 09:00', new DateTimeZone('UTC'));
        $fills = [];
        foreach (['1.4000001', '1.4000002', '1.4000003', '1.4000004', '2.10000005'] as $i => $price) {
            $fills[] = self::entry($i + 1, 1, $at->modify("+$i days"), $price);
        }

        $findings = PriceOutlier::of($fills, static fn (): array => $fills, 35);

        self::assertEquals(self::reference($fills, $fills, 35), $findings);
        self::assertCount(1, $findings);
        self::assertSame('1.4000003', $findings[0]->median, 'the mean of two middle values, rounded to 7 places');
    }

    /**
     * Fill-ups every $days days with prices of 0 to 6 places, a few free, and
     * some far off.
     *
     * @return list<FuelEntry>
     */
    private static function fills(int $vehicle, int $count, int $days): array
    {
        $start = new DateTimeImmutable('2024-01-01 08:00', new DateTimeZone('UTC'));
        $fills = [];
        for ($i = 0; $i < $count; $i++) {
            $places = mt_rand(0, 6);
            $price = number_format(mt_rand(120, 190) / 100 + mt_rand(0, 999999) / 1e8, $places, '.', '');
            $price = match (mt_rand(0, 19)) {
                0 => Decimal::multiply($price, '10', $places),
                1 => Decimal::divide($price, '1.5', $places),
                2 => '0.000',
                default => $price,
            };
            $fills[] = self::entry($vehicle * 1000 + $i, $vehicle, $start->modify(sprintf('+%d days', $days * $i)), $price);
        }

        return $fills;
    }

    private static function entry(int $id, int $vehicle, DateTimeImmutable $at, string $price): FuelEntry
    {
        return new FuelEntry($id, $vehicle, new FuelEntryData($at, '10000', Fuel::Petrol, '40.00', $price, '55.60'), $at, $at);
    }

    /**
     * The check as it was before Phase 54.5, on decimal strings throughout.
     *
     * @param list<FuelEntry> $entries
     * @param list<FuelEntry> $owners
     * @return list<PriceFinding>
     */
    private static function reference(array $entries, array $owners, int $percent): array
    {
        $high = Decimal::divide((string) (100 + $percent), '100', 2);
        $low = Decimal::divide((string) (100 - $percent), '100', 2);
        $findings = [];
        foreach (self::sorted($entries) as $entry) {
            if (Decimal::compare($entry->data->pricePerUnit, '0') <= 0) {
                continue;
            }
            $prices = self::near($entry, $entries);
            $wider = false;
            if (count($prices) < PriceOutlier::MIN_NEIGHBOURS) {
                $prices = self::near($entry, $owners);
                $wider = true;
            }
            if (count($prices) < PriceOutlier::MIN_NEIGHBOURS) {
                continue;
            }
            $median = Outlier::median($prices);
            $price = $entry->data->pricePerUnit;
            $above = Decimal::compare($price, Decimal::multiply($median, $high, Outlier::SCALE + 3)) > 0;
            $below = Decimal::compare($price, Decimal::multiply($median, $low, Outlier::SCALE + 3)) < 0;
            if ($above || $below) {
                $ratio = Outlier::ratio($price, $median);
                $findings[] = new PriceFinding($entry, $median, $ratio, Outlier::isDigitSlip($ratio), $wider);
            }
        }

        return $findings;
    }

    /**
     * @param list<FuelEntry> $entries
     * @return list<string>
     */
    private static function near(FuelEntry $entry, array $entries): array
    {
        $prices = [];
        foreach (self::sorted($entries) as $other) {
            $apart = abs($other->data->filledAt->getTimestamp() - $entry->data->filledAt->getTimestamp());
            if (
                Decimal::compare($other->data->pricePerUnit, '0') > 0
                && $apart <= PriceOutlier::WINDOW_DAYS * 86400
                && !($other->id === $entry->id && $other->vehicleId === $entry->vehicleId)
            ) {
                $prices[] = $other->data->pricePerUnit;
            }
        }

        return $prices;
    }

    /**
     * @param list<FuelEntry> $entries
     * @return list<FuelEntry>
     */
    private static function sorted(array $entries): array
    {
        usort(
            $entries,
            static fn (FuelEntry $a, FuelEntry $b): int => ($a->data->filledAt <=> $b->data->filledAt)
                ?: ($a->vehicleId <=> $b->vehicleId)
                ?: ($a->id <=> $b->id),
        );

        return $entries;
    }
}
