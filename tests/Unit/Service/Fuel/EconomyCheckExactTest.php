<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Fuel;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Service\Fuel\EconomyCheck;
use Logbook\Service\Fuel\EconomyVerdict;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelEconomy;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The economy check's median and band test in integers (Phase 54.5, K149)
 * give exactly what they gave on decimal strings: every checked segment's
 * consumption, baseline, ratio and verdict, for a plug-in hybrid's two
 * series with partials, missed fill-ups and tanks on the band's edges.
 */
final class EconomyCheckExactTest extends TestCase
{
    /** @var array<string, array{more: string, less: string}> */
    private const array BANDS = [
        'liquid' => ['more' => '1.25', 'less' => '0.80'],
        'electric' => ['more' => '1.35', 'less' => '0.74'],
        'gas' => ['more' => '1.25', 'less' => '0.80'],
    ];

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 4, 5] as $seed) {
            yield "run $seed" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testEveryCheckIsTheSameAsWithDecimalStrings(int $seed): void
    {
        mt_srand($seed);
        $history = FuelEconomy::analyse(self::fills(300));
        $checks = EconomyCheck::of($history);

        $compared = 0;
        $flagged = 0;
        foreach (EnergyKind::cases() as $kind) {
            foreach (self::reference($history->measured($kind), $kind) as $id => $expected) {
                $check = $checks->for($id);
                self::assertNotNull($check);
                $actual = [$check->verdict, $check->consumption, $check->baseline, $check->ratio];
                self::assertSame($expected, $actual, "fill-up $id");
                $compared++;
                $flagged += $expected[0] === EconomyVerdict::More || $expected[0] === EconomyVerdict::Less ? 1 : 0;
            }
        }
        self::assertSame($compared, count($checks->checks));
        self::assertGreaterThan(0, $flagged, 'the run has flags to compare');
    }

    /**
     * The check before Phase 54.5, on decimal strings: [verdict, consumption, baseline, ratio] by fill-up.
     *
     * @param list<FillEconomy> $measured
     * @return array<int, array{EconomyVerdict, string, ?string, ?string}>
     */
    private static function reference(array $measured, EnergyKind $kind): array
    {
        $earlier = [];
        $out = [];
        foreach ($measured as $fill) {
            $segment = $fill->segment;
            if ($segment === null || !EconomyCheck::isCheckable($segment)) {
                continue;
            }
            $consumption = EconomyCheck::consumption($segment);
            $baseline = null;
            if (count($earlier) >= EconomyCheck::MIN_BASELINE_SEGMENTS) {
                $values = array_slice($earlier, -EconomyCheck::BASELINE_SEGMENTS);
                usort($values, Decimal::compare(...));
                $middle = intdiv(count($values), 2);
                $baseline = count($values) % 2 === 1
                    ? Decimal::round($values[$middle], EconomyCheck::SCALE + 1)
                    : Decimal::divide(Decimal::add($values[$middle - 1], $values[$middle]), '2', EconomyCheck::SCALE + 1);
            }
            $earlier[] = $consumption;
            $band = self::BANDS[$kind->value];
            $verdict = match (true) {
                $baseline === null => EconomyVerdict::NotChecked,
                Decimal::compare($consumption, Decimal::multiply($baseline, $band['more'], 12)) >= 0 => EconomyVerdict::More,
                Decimal::compare($consumption, Decimal::multiply($baseline, $band['less'], 12)) <= 0 => EconomyVerdict::Less,
                default => EconomyVerdict::Normal,
            };
            $out[$fill->entry->id] = [
                $verdict,
                $consumption,
                $baseline,
                $baseline === null ? null : Decimal::divide($consumption, $baseline, EconomyCheck::SCALE),
            ];
        }

        return $out;
    }

    /**
     * A plug-in hybrid's fill-ups and charges: mostly usual tanks, some
     * partial, some after a missed fill-up, some far off, some exactly
     * 1.25 times or 0.8 times the usual (the band's edges).
     *
     * @return list<FuelEntry>
     */
    private static function fills(int $count): array
    {
        $odometer = 10000;
        $start = new DateTimeImmutable('2025-01-01 08:00', new DateTimeZone('UTC'));
        $fills = [];
        for ($id = 1; $id <= $count; $id++) {
            $electric = mt_rand(0, 3) === 0;
            $distance = mt_rand(0, 9) === 0 ? mt_rand(20, 99) : mt_rand(300, 700);
            $odometer += $distance;
            $per100 = $electric ? 18 : 6;
            $volume = $per100 * $distance / 100 * (mt_rand(85, 115) / 100);
            $volume = match (mt_rand(0, 24)) {
                0 => $per100 * $distance / 100 * 1.25,
                1 => $per100 * $distance / 100 * 0.8,
                2 => $volume * 2,
                3 => $volume / 3,
                default => $volume,
            };
            $at = $start->modify(sprintf('+%d days', 3 * $id));
            $fills[] = new FuelEntry($id, 1, new FuelEntryData(
                filledAt: $at,
                odometerKm: $odometer . '.000',
                fuel: $electric ? Fuel::Electricity : Fuel::Petrol,
                volume: number_format($volume, mt_rand(1, 3), '.', ''),
                pricePerUnit: '1.500000',
                totalCost: '0',
                isPartial: mt_rand(0, 11) === 0,
                isMissedPrevious: mt_rand(0, 29) === 0,
            ), $at, $at);
        }

        return $fills;
    }
}
