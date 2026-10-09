<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Insights;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\Insights\FuelSaving;
use Logbook\Tests\Unit\Service\Fuel\BuildsFills;
use PHPUnit\Framework\TestCase;

/**
 * *Fuel saving*'s yearly volume (#357) and its 30-day average price paid
 * (#352), spec.md §7.8. "Now" is 3 Oct 2026, 07:00 UTC.
 */
final class FuelSavingVolumeTest extends TestCase
{
    use BuildsFills;

    private const string NOW = '2026-10-03T07:00:00Z';

    public function testAFullYearIsTheGradesLitresOverTwelveMonths(): void
    {
        $entries = [
            $this->fill('2025-06-01', '40'),
            // In the window: 8 × 40 L of E10, and one E5 that doesn't count.
            ...$this->every('2025-11-01', 8, 30, '40'),
            $this->fill('2026-09-20', '30', FuelGrade::E5_98),
        ];

        $volume = FuelSaving::yearlyVolume($entries, FuelGrade::E10_95, $this->now());
        self::assertSame(['litres' => '320', 'months' => null], $volume);
    }

    public function testUnderAYearItIsScaledFromTheLitresAfterTheFirst(): void
    {
        // Eight fill-ups of 50 L a fortnight apart from 1 June: 98 days, 350 L after the first.
        $volume = FuelSaving::yearlyVolume($this->every('2026-06-01', 8, 14, '50'), FuelGrade::E10_95, $this->now());

        self::assertNotNull($volume);
        self::assertSame('1303.571429', $volume['litres'], '350 × 365 ÷ 98');
        self::assertSame(3, $volume['months']);
    }

    public function testSixFillUpsOverNinetyDaysAtLeast(): void
    {
        $now = $this->now();
        self::assertNull(FuelSaving::yearlyVolume($this->every('2026-05-01', 5, 30, '40'), FuelGrade::E10_95, $now));
        $grade = FuelGrade::E10_95;
        self::assertNull(FuelSaving::yearlyVolume($this->every('2026-08-01', 6, 17, '40'), $grade, $now), '85 days');
        self::assertNotNull(FuelSaving::yearlyVolume($this->every('2026-07-01', 6, 18, '40'), $grade, $now), '90 days');
    }

    public function testTheAverageIsTheTotalOverTheVolumeInTheLastThirtyDays(): void
    {
        $entries = [
            $this->fill('2026-08-20', '40', total: '80.00'),
            $this->fill('2026-09-10', '40', total: '55.60'),
            $this->fill('2026-09-30', '20', total: '28.20'),
            $this->fill('2026-09-25', '30', FuelGrade::E5_98, total: '50.00'),
        ];

        self::assertSame('1.396667', FuelSaving::averagePaid($entries, FuelGrade::E10_95, $this->now()), '£83.80 ÷ 60 L');
        self::assertNull(FuelSaving::averagePaid([$entries[0]], FuelGrade::E10_95, $this->now()), 'nothing in 30 days');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    /**
     * @return list<FuelEntry>
     */
    private function every(string $from, int $count, int $days, string $litres): array
    {
        $fills = [];
        for ($i = 0; $i < $count; $i++) {
            $day = (new DateTimeImmutable($from))->modify(sprintf('+%d days', $days * $i))->format('Y-m-d');
            $fills[] = $this->fill($day, $litres);
        }

        return $fills;
    }

    private function fill(string $day, string $litres, FuelGrade $grade = FuelGrade::E10_95, ?string $total = null): FuelEntry
    {
        return $this->fillAt($day . ' 08:00:00', '10000', $litres, '1.389', $grade, Fuel::Petrol, total: $total);
    }
}
