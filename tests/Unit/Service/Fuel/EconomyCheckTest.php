<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Fuel;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Service\Fuel\EconomyCheck;
use Logbook\Service\Fuel\EconomyChecks;
use Logbook\Service\Fuel\EconomyVerdict;
use Logbook\Service\Fuel\FuelEconomy;
use Logbook\Support\Units\ConsumptionUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Worked examples for the economy check (spec.md §7.3). Distances in km,
 * volumes in litres (kWh for electricity); consumption per 100 km.
 */
final class EconomyCheckTest extends TestCase
{
    private int $nextId = 1;
    private int $odometer = 10000;

    // --- Baseline ---------------------------------------------------------

    public function testTheBaselineIsTheMedianOfTheLastTenEarlierSegments(): void
    {
        // Ten thrifty segments (4.0), then ten at 8.0: only the last ten count.
        $fills = [$this->full(0, '40')];
        foreach (array_fill(0, 10, '40') as $volume) {
            $fills[] = $this->full(1000, $volume);
        }
        foreach (array_fill(0, 10, '80') as $volume) {
            $fills[] = $this->full(1000, $volume);
        }
        $fills[] = $target = $this->full(1000, '80');

        $check = self::checks($fills)->for($target->id);
        self::assertNotNull($check);
        self::assertSame('8.0000000', $check->baseline);
        self::assertSame(EconomyVerdict::Normal, $check->verdict);
    }

    public function testAnEvenCountTakesTheMeanOfTheTwoMiddleSegments(): void
    {
        $fills = [$this->full(0, '40')];
        foreach (['60', '110', '70', '100', '80', '90'] as $volume) {
            $fills[] = $this->full(1000, $volume);
        }
        $fills[] = $target = $this->full(1000, '85');

        self::assertSame('8.5000000', self::checks($fills)->for($target->id)?->baseline);
    }

    public function testFewerThanFiveEarlierSegmentsIsNotChecked(): void
    {
        $fills = $this->usual(4);
        $fills[] = $fifth = $this->full(1000, '200');
        $fills[] = $sixth = $this->full(1000, '200');

        $checks = self::checks($fills);
        self::assertSame(EconomyVerdict::NotChecked, $checks->for($fifth->id)?->verdict, 'only four earlier segments');
        self::assertFalse($checks->isFlagged($fifth->id));
        self::assertNull($checks->for($fifth->id)->baseline);
        self::assertSame(EconomyVerdict::More, $checks->for($sixth->id)?->verdict, 'five earlier segments');
    }

    public function testAShortSegmentIsNeitherCheckedNorInABaseline(): void
    {
        $fills = $this->usual(4);
        $fills[] = $short = $this->full(90, '30');   // 33 L/100 km over 90 km
        $fills[] = $target = $this->full(1000, '200');

        $checks = self::checks($fills);
        self::assertNull($checks->for($short->id), 'a 90 km segment is not checked');
        self::assertSame(
            EconomyVerdict::NotChecked,
            $checks->for($target->id)?->verdict,
            'the short segment does not count as one of the five',
        );

        $fills[] = $next = $this->full(1000, '80');
        self::assertSame('8.0000000', self::checks($fills)->for($next->id)?->baseline, 'nor does it move the median');
    }

    public function testAHundredKilometresIsCheckable(): void
    {
        $fills = $this->usual(5);
        $fills[] = $target = $this->full(100, '20');

        self::assertSame(EconomyVerdict::More, self::checks($fills)->for($target->id)?->verdict);
    }

    public function testANewerFillUpNeverChangesAnOlderVerdict(): void
    {
        $fills = $this->usual(5);
        $fills[] = $odd = $this->full(1000, '120');
        $before = self::checks($fills)->for($odd->id);

        $fills[] = $this->full(1000, '10');
        $fills[] = $this->full(1000, '300');
        $after = self::checks($fills)->for($odd->id);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame(EconomyVerdict::More, $after->verdict);
        self::assertSame($before->verdict, $after->verdict);
        self::assertSame($before->baseline, $after->baseline);
        self::assertSame($before->ratio, $after->ratio);
    }

    // --- Bands ------------------------------------------------------------

    /**
     * @return iterable<string, array{Fuel, string, string, EconomyVerdict}>
     */
    public static function bands(): iterable
    {
        // Baseline 8 L/100 km (80 L per 1,000 km); 20 kWh/100 km for electricity.
        yield 'liquid 1.24' => [Fuel::Petrol, '80', '99.2', EconomyVerdict::Normal];
        yield 'liquid 1.25' => [Fuel::Petrol, '80', '100', EconomyVerdict::More];
        yield 'liquid 0.81' => [Fuel::Petrol, '80', '64.8', EconomyVerdict::Normal];
        yield 'liquid 0.80' => [Fuel::Petrol, '80', '64', EconomyVerdict::Less];
        yield 'electric 1.34' => [Fuel::Electricity, '200', '268', EconomyVerdict::Normal];
        yield 'electric 1.35' => [Fuel::Electricity, '200', '270', EconomyVerdict::More];
        yield 'electric 0.75' => [Fuel::Electricity, '200', '150', EconomyVerdict::Normal];
        yield 'electric 0.74' => [Fuel::Electricity, '200', '148', EconomyVerdict::Less];
        yield 'diesel is liquid fuel' => [Fuel::Diesel, '80', '64', EconomyVerdict::Less];
    }

    #[DataProvider('bands')]
    public function testBandEdges(Fuel $fuel, string $usual, string $volume, EconomyVerdict $expected): void
    {
        $fills = $this->usual(5, $usual, $fuel);
        $fills[] = $target = $this->full(1000, $volume, fuel: $fuel);

        $check = self::checks($fills)->for($target->id);
        self::assertNotNull($check);
        self::assertSame($expected, $check->verdict);
        self::assertSame($expected->isFlag(), $check->isFlagged());
    }

    public function testComparedInFuelUsedSoEveryUnitAgrees(): void
    {
        $fills = $this->usual(5);
        $fills[] = $more = $this->full(1000, '100');
        $fills[] = $this->full(1000, '80');
        $fills[] = $less = $this->full(1000, '64');
        $checks = self::checks($fills);

        // 25% more fuel is 20% fewer miles per gallon, UK or US alike: a band
        // applied to mpg would be lopsided, so the check never uses it.
        foreach ([ConsumptionUnit::MpgUk, ConsumptionUnit::MpgUs] as $unit) {
            $usual = $unit->fromDistanceAndVolume(1000.0, 80.0);
            self::assertNotNull($usual);
            self::assertEqualsWithDelta(0.8, $unit->fromDistanceAndVolume(1000.0, 100.0) / $usual, 1e-9);
            self::assertEqualsWithDelta(1.25, $unit->fromDistanceAndVolume(1000.0, 64.0) / $usual, 1e-9);
        }
        self::assertSame('1.250000', $checks->for($more->id)?->ratio);
        self::assertSame(EconomyVerdict::More, $checks->for($more->id)->verdict);
        self::assertSame('0.800000', $checks->for($less->id)?->ratio);
        self::assertSame(EconomyVerdict::Less, $checks->for($less->id)->verdict);
        self::assertEqualsWithDelta(0.25, $checks->for($more->id)->difference(), 1e-9);
        self::assertEqualsWithDelta(0.20, $checks->for($less->id)->difference(), 1e-9);
    }

    // --- Pairs ------------------------------------------------------------

    public function testAnOdometerTypedTooHighFlagsAPairNamingThatFillUp(): void
    {
        // 1,300 km tanks at 8 L/100 km; the middle reading typed 1,000 km too high.
        $fills = $this->usual(5, '104', distance: 1300);
        $fills[] = $opening = $this->full(1300, '104');
        $fills[] = $typo = $this->full(2300, '104');
        $fills[] = $after = $this->full(300, '104');
        $checks = self::checks($fills);

        $first = $checks->for($typo->id);
        $second = $checks->for($after->id);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(EconomyVerdict::Less, $first->verdict);
        self::assertSame(EconomyVerdict::More, $second->verdict);
        self::assertSame($typo->id, $first->pairShared?->id);
        self::assertSame($typo->id, $second->pairShared?->id);
        self::assertTrue($first->pairNormal, 'taken together: 208 L over 2,600 km');
        self::assertTrue($second->pairNormal);

        // Links: the first tank's other fill-up is where it opened; the second's is the shared one.
        self::assertSame($opening->id, $first->otherEntry()?->id);
        self::assertSame($typo->id, $second->otherEntry()?->id);
    }

    public function testOppositeFlagsThatAreStillOddTogetherArePairedButNotNormal(): void
    {
        $fills = $this->usual(5, '104', distance: 1300);
        $fills[] = $this->full(1300, '104');
        $fills[] = $a = $this->full(2300, '104');   // less
        $fills[] = $b = $this->full(300, '200');    // more, and far more fuel overall

        $check = self::checks($fills)->for($a->id);
        self::assertSame($a->id, $check?->pairShared?->id);
        self::assertFalse($check->pairNormal);
    }

    public function testTwoGenuinelyBadTanksInARowAreNotAPair(): void
    {
        $fills = $this->usual(5);
        $fills[] = $a = $this->full(1000, '120');
        $fills[] = $b = $this->full(1000, '120');
        $checks = self::checks($fills);

        self::assertTrue($checks->isFlagged($a->id));
        self::assertTrue($checks->isFlagged($b->id));
        self::assertNull($checks->for($a->id)?->pairShared);
        self::assertNull($checks->for($b->id)?->pairShared);
    }

    public function testAGapBetweenOppositeFlagsIsNotAPair(): void
    {
        $fills = $this->usual(5);
        $fills[] = $less = $this->full(1000, '40');
        $fills[] = $this->full(1000, '80', missedPrevious: true);   // restarts: shares nothing
        $fills[] = $more = $this->full(1000, '120');
        $checks = self::checks($fills);

        self::assertSame(EconomyVerdict::Less, $checks->for($less->id)?->verdict);
        self::assertSame(EconomyVerdict::More, $checks->for($more->id)?->verdict);
        self::assertNull($checks->for($less->id)->pairShared);
        self::assertNull($checks->for($more->id)->pairShared);
    }

    public function testATypoSoLargeTheNextReadingGoesBackwardsIsFlaggedAlone(): void
    {
        $fills = $this->usual(5, '104', distance: 1300);
        $fills[] = $this->full(1300, '104');
        $fills[] = $typo = $this->full(10300, '104');   // 9,000 km too high
        $fills[] = $next = $this->full(-7700, '104');   // not past the typo: measuring restarts
        $checks = self::checks($fills);

        self::assertSame(EconomyVerdict::Less, $checks->for($typo->id)?->verdict);
        self::assertNull($checks->for($typo->id)->pairShared);
        self::assertNull($checks->for($next->id), 'an unusable reading closes no segment');
    }

    // --- Series -----------------------------------------------------------

    public function testAPlugInHybridsPetrolAndChargingAreCheckedSeparately(): void
    {
        $fills = [$this->full(0, '40'), $this->full(0, '100', fuel: Fuel::Electricity)];
        for ($i = 0; $i < 5; $i++) {
            $fills[] = $this->full(1000, '80');                       // 8 L/100 km
            $fills[] = $this->full(0, '200', fuel: Fuel::Electricity);   // 1,000 km since the last charge
        }
        $fills[] = $petrol = $this->full(1000, '100');
        $fills[] = $charge = $this->full(0, '200', fuel: Fuel::Electricity);
        $checks = self::checks($fills);

        self::assertSame('8.0000000', $checks->for($petrol->id)?->baseline);
        self::assertSame(EconomyVerdict::More, $checks->for($petrol->id)->verdict);
        self::assertSame('20.0000000', $checks->for($charge->id)?->baseline);
        self::assertSame(EconomyVerdict::Normal, $checks->for($charge->id)->verdict);
    }

    public function testAMissedFillUpRestartsTheSegmentButKeepsTheBaseline(): void
    {
        $fills = $this->usual(5);
        $fills[] = $restart = $this->full(1000, '300', missedPrevious: true);
        $fills[] = $after = $this->full(1000, '100');
        $checks = self::checks($fills);

        self::assertNull($checks->for($restart->id), 'a missed-previous fill-up closes no segment (§7.3)');
        self::assertSame('8.0000000', $checks->for($after->id)?->baseline, 'earlier segments still count');
        self::assertSame(EconomyVerdict::More, $checks->for($after->id)->verdict);
    }

    public function testPartialsJoinTheSegmentTheyAreIn(): void
    {
        $fills = $this->usual(5);
        $fills[] = $this->partial(500, '40');
        $fills[] = $closing = $this->full(500, '40');

        $check = self::checks($fills)->for($closing->id);
        self::assertSame('8.000000', $check?->consumption, '80 L over 1,000 km');
        self::assertSame(EconomyVerdict::Normal, $check->verdict);
    }

    // --- Confirmation -----------------------------------------------------

    public function testAConfirmedFigureHidesTheFlag(): void
    {
        $fills = $this->usual(5);
        $fills[] = $thirsty = $this->full(1000, '120', confirmed: '12.000000');
        $check = self::checks($fills)->for($thirsty->id);
        self::assertNotNull($check);

        self::assertSame(EconomyVerdict::More, $check->verdict, 'still more than usual');
        self::assertFalse($check->isFlagged());
        self::assertTrue($check->isConfirmedFlag());
        self::assertSame(0, self::checks($fills)->flaggedCount());
    }

    public function testEditingTheVolumeBringsTheFlagBack(): void
    {
        $fills = $this->usual(5);
        $fills[] = $edited = $this->full(1000, '125', confirmed: '12.000000');

        self::assertTrue(self::checks($fills)->isFlagged($edited->id));
    }

    public function testAddingAPartialInsideTheSegmentBringsTheFlagBack(): void
    {
        $fills = $this->usual(5);
        $fills[] = $this->partial(400, '10');
        $fills[] = $closing = $this->full(600, '120', confirmed: '12.000000');

        self::assertSame('13.000000', self::checks($fills)->for($closing->id)?->consumption);
        self::assertTrue(self::checks($fills)->isFlagged($closing->id));
    }

    public function testAnUnrelatedFillUpLeavesItConfirmed(): void
    {
        $fills = $this->usual(5);
        $fills[] = $confirmed = $this->full(1000, '120', confirmed: '12.000000');
        $fills[] = $this->full(1000, '80');
        $fills[] = $this->full(1000, '85');

        $check = self::checks($fills)->for($confirmed->id);
        self::assertTrue($check?->isConfirmedFlag());
        self::assertFalse(self::checks($fills)->isFlagged($confirmed->id));
    }

    public function testAConfirmedTankIsNeverPartOfAPair(): void
    {
        $fills = $this->usual(5, '104', distance: 1300);
        $fills[] = $this->full(1300, '104');
        $fills[] = $typo = $this->full(2300, '104', confirmed: '4.521739');
        $fills[] = $after = $this->full(300, '104');
        $checks = self::checks($fills);

        self::assertTrue($checks->for($typo->id)?->isConfirmedFlag());
        self::assertNull($checks->for($after->id)?->pairShared);
        self::assertTrue($checks->isFlagged($after->id));
    }

    public function testConsumptionIsExactToSixPlaces(): void
    {
        $fills = [$this->full(0, '40'), $closing = $this->full(657, '43.217')];

        $history = FuelEconomy::analyse($fills);
        $segment = $history->fills[1]->segment;
        self::assertNotNull($segment);
        self::assertSame('6.577930', EconomyCheck::consumption($segment));
        self::assertTrue(EconomyCheck::isCheckable($segment));
        self::assertSame($fills[0]->id, $segment->opening?->id);
        self::assertSame($closing->id, $history->fills[1]->entry->id);
    }

    // --- Helpers ----------------------------------------------------------

    /**
     * @param list<FuelEntry> $fills
     */
    private static function checks(array $fills): EconomyChecks
    {
        return EconomyCheck::of(FuelEconomy::analyse($fills));
    }

    /**
     * A starting full fill, then $segments usual segments.
     *
     * @return list<FuelEntry>
     */
    private function usual(int $segments, string $volume = '80', Fuel $fuel = Fuel::Petrol, int $distance = 1000): array
    {
        $fills = [$this->full(0, $volume, fuel: $fuel)];
        for ($i = 0; $i < $segments; $i++) {
            $fills[] = $this->full($distance, $volume, fuel: $fuel);
        }

        return $fills;
    }

    private function full(
        int $distance,
        string $volume,
        bool $missedPrevious = false,
        Fuel $fuel = Fuel::Petrol,
        ?string $confirmed = null,
    ): FuelEntry {
        return $this->entry($distance, $volume, false, $missedPrevious, $fuel, $confirmed);
    }

    private function partial(int $distance, string $volume): FuelEntry
    {
        return $this->entry($distance, $volume, true, false, Fuel::Petrol, null);
    }

    /**
     * A fill-up $distance km after the previous one (of any kind: the
     * odometer is one series).
     */
    private function entry(
        int $distance,
        string $volume,
        bool $partial,
        bool $missedPrevious,
        Fuel $fuel,
        ?string $confirmed,
    ): FuelEntry {
        $id = $this->nextId++;
        $this->odometer += $distance;
        $at = (new DateTimeImmutable('2026-01-01 08:00', new DateTimeZone('UTC')))->modify(sprintf('+%d days', $id));

        return new FuelEntry(
            $id,
            1,
            new FuelEntryData(
                filledAt: $at,
                odometerKm: $this->odometer . '.000',
                fuel: $fuel,
                volume: number_format((float) $volume, 3, '.', ''),
                pricePerUnit: '1.500000',
                totalCost: '0',
                isPartial: $partial,
                isMissedPrevious: $missedPrevious,
            ),
            $at,
            $at,
            $confirmed,
        );
    }
}
