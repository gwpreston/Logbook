<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Trip;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Trip\MileageRateSet;
use Logbook\Domain\Trip\MileageRateSetData;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Trip\ClaimCalculator;
use Logbook\Service\Trip\ClaimTotals;
use Logbook\Service\Trip\ClaimValuation;
use Logbook\Service\Trip\ValuedTrip;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Units\DistanceUnit;
use PHPUnit\Framework\TestCase;

/**
 * The claim maths (spec.md §7.23; Phase 22 *Tests: Claim maths*).
 */
final class ClaimCalculatorTest extends TestCase
{
    private const string GB = '04-06';
    private const int CAR = 1;
    private const int CAR_TWO = 2;
    private const int BIKE = 3;

    private int $nextId = 1;

    public function testTheTripThatCrossesTenThousandMilesIsSplitExactly(): void
    {
        $trips = [];
        for ($i = 0; $i < 4; $i++) {
            $trips[] = $this->trip(sprintf('2026-0%d-10', 5 + $i), '2475');
        }
        $crossing = $this->trip('2026-10-01', '150');
        $trips[] = $crossing;
        $after = $this->trip('2026-10-02', '10');
        $trips[] = $after;

        $valued = $this->value($trips, [self::hmrc2026()]);

        $split = $this->find($valued, $crossing);
        self::assertTrue($split->isSplit());
        self::assertSame('100.000', $split->lines[0]->distance);
        self::assertSame('0.5500', $split->lines[0]->rate);
        self::assertSame('50.000', $split->lines[1]->distance);
        self::assertSame('0.2500', $split->lines[1]->rate);
        self::assertSame('67.50', $split->mileageAmount, '100 × 55p + 50 × 25p');

        $next = $this->find($valued, $after);
        self::assertFalse($next->isSplit());
        self::assertSame('0.2500', $next->lines[0]->rate);
        self::assertSame('2.50', $next->mileageAmount);

        self::assertSame('1361.25', $this->find($valued, $trips[0])->mileageAmount, '2,475 × 55p');
    }

    public function testTheThresholdCountsAcrossAllTheClaimantsCarsInDateOrderThenTheOrderLogged(): void
    {
        $first = $this->trip('2026-05-01', '9990', self::CAR, '2026-05-01 18:00');
        // Same day on the other car, logged earlier: it comes first.
        $earlier = $this->trip('2026-05-01', '5', self::CAR_TWO, '2026-05-01 09:00');
        $crossing = $this->trip('2026-05-02', '20', self::CAR_TWO);

        $valued = $this->value([$crossing, $first, $earlier], [self::hmrc2026()]);

        self::assertSame([$earlier->id, $first->id, $crossing->id], array_map(
            static fn (ValuedTrip $trip): int => $trip->trip->id,
            $valued->trips,
        ));
        $split = $this->find($valued, $crossing);
        self::assertSame(['5.000', '15.000'], [$split->lines[0]->distance, $split->lines[1]->distance]);
    }

    public function testAMidYearRateChangeKeepsTheYearsRunningTotal(): void
    {
        $mid = self::set('2026-10-01', car: '0.6000', threshold: '10000', after: '0.3000');
        $before = $this->trip('2026-06-01', '9950');
        $crossing = $this->trip('2026-10-05', '100');

        $valued = $this->value([$before, $crossing], [self::hmrc2026(), $mid]);

        self::assertSame('5472.50', $this->find($valued, $before)->mileageAmount, '9,950 × 55p');
        $split = $this->find($valued, $crossing);
        self::assertSame($mid->id, $split->rateSet?->id);
        self::assertSame(['50.000', '50.000'], [$split->lines[0]->distance, $split->lines[1]->distance]);
        self::assertSame(['0.6000', '0.3000'], [$split->lines[0]->rate, $split->lines[1]->rate]);
        self::assertSame('45.00', $split->mileageAmount, '50 × 60p + 50 × 30p');
    }

    public function testFiveAprilAndSixAprilAreInDifferentTaxYearsAndTheCountRestarts(): void
    {
        $last = $this->trip('2026-04-05', '10050');
        $first = $this->trip('2026-04-06', '100');

        $valued = $this->value([$first, $last], [self::hmrc2011(), self::hmrc2026()]);

        $old = $this->find($valued, $last);
        self::assertSame('0.4500', $old->lines[0]->rate, 'the old rate up to 5 April');
        self::assertSame(['10000.000', '50.000'], [$old->lines[0]->distance, $old->lines[1]->distance]);

        $new = $this->find($valued, $first);
        self::assertFalse($new->isSplit(), 'a new tax year starts the count again');
        self::assertSame('0.5500', $new->lines[0]->rate);
        self::assertSame('55.00', $new->mileageAmount);
    }

    public function testBikesUseTheBikeRateOutsideTheThreshold(): void
    {
        $car = $this->trip('2026-05-01', '10000');
        $bike = $this->trip('2026-05-02', '100', self::BIKE);
        $carAfter = $this->trip('2026-05-03', '10');

        $valued = $this->value([$car, $bike, $carAfter], [self::hmrc2026()]);

        $onBike = $this->find($valued, $bike);
        self::assertSame('0.2400', $onBike->lines[0]->rate);
        self::assertSame('24.00', $onBike->mileageAmount);
        self::assertSame('0.2500', $this->find($valued, $carAfter)->lines[0]->rate, 'past the threshold, whatever the bike did');

        $bikeUnderThreshold = $this->value([$this->trip('2026-05-01', '9990', self::BIKE), $this->trip('2026-05-02', '20')], [self::hmrc2026()]);
        self::assertSame('0.5500', $bikeUnderThreshold->trips[1]->lines[0]->rate, 'bike miles never count towards the car threshold');
        self::assertFalse($bikeUnderThreshold->trips[1]->isSplit());
    }

    public function testWithoutABikeRateBikesUseTheCarRateWithNoThreshold(): void
    {
        $set = self::set('2026-04-06', car: '0.5500', threshold: '10', after: '0.2500', bike: null);

        $valued = $this->value([$this->trip('2026-05-01', '100', self::BIKE)], [$set]);

        self::assertFalse($valued->trips[0]->isSplit());
        self::assertSame('0.5500', $valued->trips[0]->lines[0]->rate);
    }

    public function testPassengersArePassengersTimesDistanceTimesThePassengerRate(): void
    {
        $trip = $this->trip('2026-05-01', '50', passengers: 2);

        $valued = $this->value([$trip], [self::hmrc2026()]);

        $row = $valued->trips[0];
        self::assertSame('27.50', $row->mileageAmount);
        self::assertSame('5.00', $row->passengerAmount, '2 × 50 × 5p');
        self::assertSame('0.0500', $row->passengerRate);
        self::assertSame('32.50', $row->amount());
    }

    public function testKilometreRatesValueMileTripsExactly(): void
    {
        $set = self::set('2026-01-01', unit: DistanceUnit::Kilometre, currency: 'EUR', car: '0.3000', threshold: null, after: null, bike: null, passenger: null);
        $trip = $this->trip('2026-05-01', '10');

        $valued = $this->value([$trip], [$set]);

        self::assertSame('16.093', $valued->trips[0]->distance, '10 mi in km');
        self::assertSame('4.83', $valued->trips[0]->mileageAmount, '16.093 × 0.30 = 4.8279');
        self::assertSame('EUR', $valued->trips[0]->currency());
    }

    public function testAmountsAreRoundedPerTripAndTotalsAreTheirSums(): void
    {
        $set = self::set('2026-04-06', car: '0.4500', threshold: null, after: null);
        $trips = [$this->trip('2026-05-01', '1.3'), $this->trip('2026-05-02', '1.3'), $this->trip('2026-05-03', '1.3')];

        $valued = $this->value($trips, [$set]);

        foreach ($valued->trips as $row) {
            self::assertSame('0.59', $row->mileageAmount, '1.3 × 45p = 58.5p, rounded half up per trip');
        }
        $totals = ClaimTotals::byCurrency($valued->trips);
        self::assertSame('1.77', $totals[0]->approvedAmount(), 'the sum of the rounded trips, not 1.76');
        self::assertSame('3.900', $totals[0]->totalDistance());
    }

    public function testAnEmployerPayingLessLeavesAPositiveDifference(): void
    {
        $set = self::set('2026-04-06', employerCar: '0.3500');
        $valued = $this->value([$this->trip('2026-05-01', '100', passengers: 1)], [$set]);

        $totals = ClaimTotals::byCurrency($valued->trips)[0];
        self::assertSame('55.00', $totals->mileageAmount);
        self::assertSame('5.00', $totals->passengerAmount);
        self::assertSame('60.00', $totals->approvedAmount());
        self::assertSame('35.00', $totals->employerAmount);
        self::assertSame('20.00', $totals->difference(), 'mileage without passengers, minus what was paid');
    }

    public function testAnEmployerPayingMoreLeavesANegativeDifference(): void
    {
        $set = self::set('2026-04-06', employerCar: '0.6000');
        $valued = $this->value([$this->trip('2026-05-01', '100')], [$set]);

        self::assertSame('-5.00', ClaimTotals::byCurrency($valued->trips)[0]->difference());
    }

    public function testWithoutEmployerRatesThereIsNoDifference(): void
    {
        $valued = $this->value([$this->trip('2026-05-01', '100')], [self::hmrc2026()]);

        $totals = ClaimTotals::byCurrency($valued->trips)[0];
        self::assertNull($totals->employerAmount);
        self::assertNull($totals->difference());
    }

    public function testABikeFallsBackToTheEmployersCarRate(): void
    {
        $set = self::set('2026-04-06', employerCar: '0.3500');
        $valued = $this->value([$this->trip('2026-05-01', '100', self::BIKE)], [$set]);

        self::assertSame('35.00', $valued->trips[0]->employerAmount);
    }

    public function testATripBeforeTheEarliestRateSetHasNoValue(): void
    {
        $early = $this->trip('2026-03-01', '50');
        $valued = $this->value([$early, $this->trip('2026-05-01', '50')], [self::hmrc2026()]);

        $row = $this->find($valued, $early);
        self::assertFalse($row->isValued());
        self::assertNull($row->amount());
        self::assertSame(1, ClaimTotals::byCurrency($valued->trips)[0]->tripCount, 'totals leave it out');
    }

    public function testMixedCurrenciesAreTotalledSeparately(): void
    {
        $euro = self::set('2026-07-01', unit: DistanceUnit::Kilometre, currency: 'EUR', car: '0.3000', threshold: null, after: null);
        $valued = $this->value([$this->trip('2026-05-01', '100'), $this->trip('2026-08-01', '10')], [self::hmrc2026(), $euro]);

        $totals = ClaimTotals::byCurrency($valued->trips);
        self::assertSame(['GBP', 'EUR'], array_map(static fn (ClaimTotals $t): string => $t->currency, $totals));
        self::assertSame('55.00', $totals[0]->approvedAmount());
        self::assertSame('4.83', $totals[1]->approvedAmount());
    }

    public function testThresholdLeftMatchesTheSplitsCount(): void
    {
        $valued = $this->value([
            $this->trip('2026-05-01', '3500'),
            $this->trip('2026-06-01', '82', self::CAR_TWO),
            $this->trip('2026-06-02', '500', self::BIKE),
        ], [self::hmrc2026()]);

        $left = $valued->thresholdLeft(self::date('2026-09-30'));
        self::assertNotNull($left);
        self::assertSame('6418.000', $left->left, '10,000 − 3,582 car miles; the bike does not count');
        self::assertSame('0.2500', $left->rateAfter);
        self::assertFalse($left->isPast());

        self::assertNull($valued->thresholdLeft(self::date('2026-01-01')), 'no set in effect');
        $nextYear = $valued->thresholdLeft(self::date('2027-04-06'));
        self::assertSame('10000.000', $nextYear?->left);
    }

    /**
     * @param list<Trip> $trips
     * @param list<MileageRateSet> $sets
     */
    private function value(array $trips, array $sets): ClaimValuation
    {
        return (new ClaimCalculator())->value($trips, [
            self::CAR => VehicleType::Car,
            self::CAR_TWO => VehicleType::Car,
            self::BIKE => VehicleType::Bike,
        ], $sets, self::GB);
    }

    private function find(ClaimValuation $valued, Trip $trip): ValuedTrip
    {
        foreach ($valued->trips as $row) {
            if ($row->trip->id === $trip->id) {
                return $row;
            }
        }
        self::fail('Trip not valued.');
    }

    private function trip(string $date, string $miles, int $vehicle = self::CAR, ?string $loggedAt = null, int $passengers = 0): Trip
    {
        $logged = new DateTimeImmutable($loggedAt ?? $date . ' 12:00', new DateTimeZone('UTC'));

        return new Trip(
            id: $this->nextId++,
            vehicleId: $vehicle,
            data: new TripData(
                travelledOn: self::date($date),
                fromPlace: 'Ballymena',
                toPlace: 'Belfast',
                distanceKm: DistanceUnit::Mile->toKmDecimal($miles, 3),
                purpose: 'Client meeting',
                passengers: $passengers,
            ),
            createdAt: $logged,
            updatedAt: $logged,
            createdBy: 1,
        );
    }

    private static function hmrc2011(): MileageRateSet
    {
        return self::set('2011-04-06', car: '0.4500');
    }

    private static function hmrc2026(): MileageRateSet
    {
        return self::set('2026-04-06');
    }

    private static function set(
        string $from,
        DistanceUnit $unit = DistanceUnit::Mile,
        string $currency = 'GBP',
        string $car = '0.5500',
        ?string $threshold = '10000',
        ?string $after = '0.2500',
        ?string $bike = '0.2400',
        ?string $passenger = '0.0500',
        ?string $employerCar = null,
    ): MileageRateSet {
        static $id = 100;
        $now = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        return new MileageRateSet(
            id: $id++,
            userId: 1,
            data: new MileageRateSetData(
                effectiveFrom: self::date($from),
                distanceUnit: $unit,
                currency: $currency,
                carRate: $car,
                carThreshold: $threshold,
                carRateAfter: $after,
                bikeRate: $bike,
                passengerRate: $passenger,
                employerCarRate: $employerCar,
                source: 'HMRC approved mileage allowance payments',
            ),
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private static function date(string $date): DateTimeImmutable
    {
        return LocalTime::parseDate($date) ?? throw new \LogicException($date);
    }
}
