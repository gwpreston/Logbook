<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Forecast;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Maintenance\DonePoint;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Forecast\Forecast;
use Logbook\Service\Forecast\ForecastCalculator;
use Logbook\Service\Forecast\ForecastHorizon;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Forecast\ForecastSource;
use Logbook\Service\Forecast\FuelRate;
use Logbook\Service\Forecast\FuelRateStatus;
use Logbook\Service\Forecast\ScheduleDue;
use Logbook\Service\Forecast\TyreDue;
use Logbook\Service\Forecast\VehicleSources;
use Logbook\Service\Maintenance\DueState;
use Logbook\Service\Maintenance\ScheduleCalculator;
use Logbook\Service\Maintenance\ScheduleState;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

/**
 * Coming up (phase-15.md §15.7): schedules with repeats, documents at their
 * term, tyres and manual reminders, last time's costs, the fuel estimate and
 * the horizon. Today is 1 Oct 2026 throughout unless a test says otherwise.
 */
final class ForecastCalculatorTest extends TestCase
{
    private const string TODAY = '2026-10-01';
    private const string LONDON = 'Europe/London';
    /** 1,000 miles a month, in km per day (the mileage log's 30.436875-day month). */
    private const float THOUSAND_MILES_A_MONTH = 1000 * DistanceUnit::KM_PER_MILE / 30.436875;

    // --- Horizon -----------------------------------------------------------

    public function testTheHorizonRunsFromTodayToTheEndOfTheEleventhMonthAfterThisOne(): void
    {
        $horizon = ForecastHorizon::from(self::date(self::TODAY));

        self::assertSame('2027-09-30', $horizon->end->format('Y-m-d'));
        self::assertCount(12, $horizon->months());
        self::assertSame('2026-10-01', $horizon->months()[0]->format('Y-m-d'));
        self::assertSame('2027-09-01', $horizon->months()[11]->format('Y-m-d'));
        self::assertSame(31, $horizon->daysIn($horizon->months()[0]), 'today counts');

        $midMonth = ForecastHorizon::from(self::date('2026-10-20'));
        self::assertSame(12, $midMonth->daysIn($midMonth->months()[0]), '20 to 31 October');
        self::assertSame('2027-09-30', $midMonth->end->format('Y-m-d'));
    }

    public function testTheHorizonStartsOnTheOwnersToday(): void
    {
        // 00:30 on 1 Oct in Auckland is still 30 Sep in UTC.
        $clock = new MutableClock(new DateTimeImmutable('2026-09-30T11:30:00Z'));
        $today = LocalTime::today($clock, new DateTimeZone('Pacific/Auckland'));
        $horizon = ForecastHorizon::from($today);

        self::assertSame('2026-10-01', $horizon->today->format('Y-m-d'));
        self::assertSame('2027-09-30', $horizon->end->format('Y-m-d'));

        $utc = ForecastHorizon::from(LocalTime::today($clock, new DateTimeZone('UTC')));
        self::assertSame('2027-08-31', $utc->end->format('Y-m-d'), 'still September in UTC');
    }

    // --- Schedules ---------------------------------------------------------

    public function testEverySixMonthsRepeatsWithinTheHorizon(): void
    {
        $forecast = self::forecast(self::sources(schedules: [
            self::schedule(months: 6, doneOn: '2026-08-15'),
        ]));

        self::assertSame(['2027-02-15', '2027-08-15'], self::dates($forecast));
        self::assertSame([1, 2], array_map(static fn (ForecastItem $i): int => $i->occurrence, $forecast->items()));
        self::assertFalse($forecast->items()[0]->projected);
        self::assertSame([], $forecast->overdue);
    }

    public function testADistanceLimitIsProjectedFromTheAverageDailyDistance(): void
    {
        $forecast = self::forecast(self::sources(
            schedules: [self::schedule(miles: 10000, doneKm: self::miles(40000), currentKm: self::miles(40000))],
            currentKm: self::miles(40000),
        ));

        $items = $forecast->items();
        self::assertCount(1, $items, 'the next one after it is 20 months out');
        self::assertTrue($items[0]->projected);
        self::assertSame('2027-08-02', $items[0]->dueOn?->format('Y-m-d'), 'about 10 months out');
        self::assertSame(10, $forecast->horizon->monthIndex($items[0]->dueOn));
    }

    public function testWithBothLimitsTheSoonerAppliesEachTime(): void
    {
        $distanceFirst = self::forecast(self::sources(
            schedules: [self::schedule(
                months: 12,
                miles: 5000,
                doneOn: self::TODAY,
                doneKm: self::miles(40000),
                currentKm: self::miles(40000),
            )],
            currentKm: self::miles(40000),
        ));
        self::assertSame(['2027-03-03', '2027-08-03'], self::dates($distanceFirst));
        self::assertTrue($distanceFirst->items()[0]->projected);
        self::assertTrue($distanceFirst->items()[1]->projected);

        $dateFirst = self::forecast(self::sources(
            schedules: [self::schedule(
                months: 3,
                miles: 10000,
                doneOn: '2026-09-15',
                doneKm: self::miles(40000),
                currentKm: self::miles(40500),
            )],
            currentKm: self::miles(40500),
        ));
        self::assertSame(['2026-12-15', '2027-03-15', '2027-06-15', '2027-09-15'], self::dates($dateFirst));
        self::assertFalse($dateFirst->items()[3]->projected);
    }

    public function testTheEndOfAMonthIsClamped(): void
    {
        $forecast = self::forecast(self::sources(schedules: [self::schedule(months: 6, doneOn: '2026-08-31')]));

        // Done on 28 Feb, the next is due 28 Aug, as logging it on the day would make it.
        self::assertSame(['2027-02-28', '2027-08-28'], self::dates($forecast));

        $leap = ForecastCalculator::forecast(
            [self::sources(schedules: [self::schedule(months: 6, doneOn: '2027-08-31', today: '2027-10-01')])],
            self::date('2027-10-01'),
        );
        self::assertSame('2028-02-29', $leap->items()[0]->dueOn?->format('Y-m-d'));
    }

    public function testAnOverdueScheduleIsListedOnceWithoutRepeats(): void
    {
        $forecast = self::forecast(self::sources(schedules: [self::schedule(months: 6, doneOn: '2026-01-01')]));

        self::assertCount(1, $forecast->overdue);
        self::assertSame('2026-07-01', $forecast->overdue[0]->dueOn?->format('Y-m-d'));
        self::assertTrue($forecast->overdue[0]->overdue);
        self::assertFalse($forecast->hasMonthItems());
        self::assertSame($forecast->overdue, $forecast->next(5));
    }

    public function testPastItsDistanceLimitItIsOverdueAtThatOdometer(): void
    {
        // Every 12 months or 5,000 mi; done a month ago and 5,200 mi since.
        $forecast = self::forecast(self::sources(
            schedules: [self::schedule(
                months: 12,
                miles: 5000,
                doneOn: '2026-09-01',
                doneKm: self::miles(40000),
                currentKm: self::miles(45200),
            )],
            currentKm: self::miles(45200),
        ));

        self::assertCount(1, $forecast->items(), 'no repeats');
        $item = $forecast->overdue[0];
        self::assertNull($item->dueOn, 'not a projected day in the past');
        self::assertSame(Decimal::add(self::miles(40000), self::miles(5000)), $item->dueKm);
        self::assertFalse($item->projected);
    }

    public function testUnderAWeekOfHistoryADistanceLimitHasNoDateYet(): void
    {
        $forecast = self::forecast(self::sources(
            schedules: [self::schedule(miles: 5000, doneKm: '40000', currentKm: '41000', kmPerDay: null)],
            currentKm: '41000',
            kmPerDay: null,
        ));

        self::assertSame([], $forecast->overdue);
        self::assertFalse($forecast->hasMonthItems());
        self::assertCount(1, $forecast->undated);
        self::assertNull($forecast->undated[0]->dueOn);
        self::assertSame(Decimal::add('40000', self::miles(5000)), $forecast->undated[0]->dueKm);
    }

    public function testAtMostTwentyFourOccurrences(): void
    {
        // Every 100 km at 50 km a day: every two days.
        $forecast = self::forecast(self::sources(
            schedules: [self::schedule(km: '100', doneOn: self::TODAY, doneKm: '40000', currentKm: '40000', kmPerDay: 50.0)],
            currentKm: '40000',
            kmPerDay: 50.0,
        ));

        self::assertCount(ForecastCalculator::MAX_OCCURRENCES, $forecast->items());
        self::assertSame(24, $forecast->items()[23]->occurrence);
    }

    public function testAScheduleWithNothingToMeasureRaisesNothing(): void
    {
        $forecast = self::forecast(self::sources(schedules: [self::schedule(months: 12)]));

        self::assertFalse($forecast->hasItems());
    }

    // --- Documents ---------------------------------------------------------

    public function testAnAnnualPolicyExpiringInTwoMonthsIsOneItem(): void
    {
        $forecast = self::forecast(self::sources(documents: [
            self::document(ComplianceType::Insurance, start: '2025-12-01', expiry: '2026-11-30', cost: '480.00'),
        ]));

        self::assertSame(['2026-11-30'], self::dates($forecast));
        $item = $forecast->items()[0];
        self::assertSame(ForecastSource::Document, $item->source);
        self::assertNull($item->title, 'named by its type: "Renew Insurance"');
        self::assertSame('insurance', $item->category);
        self::assertSame('480.000000', $item->cost?->toDecimal(6));
    }

    public function testASixMonthPolicyRenewsTwice(): void
    {
        $forecast = self::forecast(self::sources(documents: [
            self::document(ComplianceType::Insurance, start: '2026-06-01', expiry: '2026-11-30', cost: '240.00'),
        ]));

        self::assertSame(['2026-11-30', '2027-05-30'], self::dates($forecast));
        self::assertTrue($forecast->items()[1]->isRepeat());
        self::assertSame('240.000000', $forecast->items()[1]->cost?->toDecimal(6), 'a repeat costs the same');
    }

    public function testWithoutAStartDateADocumentDoesNotRepeat(): void
    {
        $forecast = self::forecast(self::sources(documents: [
            self::document(ComplianceType::Pollution, start: null, expiry: '2026-12-15'),
        ]));

        self::assertSame(['2026-12-15'], self::dates($forecast));
        self::assertNull($forecast->items()[0]->cost, 'cost 0: not known');
    }

    public function testTermsInMonthsAndDays(): void
    {
        self::assertSame(['months' => 12], ForecastCalculator::term(self::date('2026-01-01'), self::date('2026-12-31')));
        self::assertSame(['months' => 12], ForecastCalculator::term(self::date('2026-03-15'), self::date('2027-03-15')));
        self::assertSame(['months' => 6], ForecastCalculator::term(self::date('2026-06-01'), self::date('2026-11-30')));
        self::assertSame(['days' => 45], ForecastCalculator::term(self::date('2026-01-01'), self::date('2026-02-15')));
        self::assertNull(ForecastCalculator::term(self::date('2026-01-01'), self::date('2026-01-28')), 'under 28 days');
        self::assertNull(ForecastCalculator::term(null, self::date('2026-12-31')));
    }

    public function testAnExpiredDocumentIsOverdueOnce(): void
    {
        $forecast = self::forecast(self::sources(documents: [
            self::document(ComplianceType::Inspection, start: '2025-09-20', expiry: '2026-09-19', cost: '54.85'),
        ]));

        self::assertCount(1, $forecast->overdue);
        self::assertFalse($forecast->hasMonthItems());
    }

    // --- Tyres and manual reminders ---------------------------------------

    public function testTyresAndManualRemindersDoNotRepeat(): void
    {
        $forecast = self::forecast(self::sources(
            tyres: [
                new TyreDue('Tyres: front due in about 800 mi', false, self::date('2027-01-10'), '52000', true),
                new TyreDue('Tyres: rear worn', true, self::date('2026-09-01'), null, true),
                new TyreDue('Tyres: rear due in about 9,000 mi', false, null, '66000', true),
                new TyreDue('Tyres: Winter wheels over 6 years old', false, self::date('2028-02-01'), null, false),
            ],
            reminders: [
                self::manual(1, 'Fit the roof bars', '2027-06-01'),
                self::manual(2, 'Collect the V5C', '2026-09-20'),
                self::manual(3, 'Sell it', null),
            ],
        ));

        self::assertSame(['Tyres: rear worn', 'Collect the V5C'], self::titles($forecast->overdue));
        self::assertFalse($forecast->overdue[0]->projected, 'an overdue item is not "around"');
        self::assertSame(['2027-01-10', '2027-06-01'], self::dates($forecast));
        self::assertTrue($forecast->months[3]->items[0]->projected);
        self::assertSame(['Tyres: rear due in about 9,000 mi'], self::titles($forecast->undated));
        self::assertSame(ForecastSource::Reminder, $forecast->months[8]->items[0]->source);
    }

    // --- Costs -------------------------------------------------------------

    public function testTheLatestCompletingEntrySetsTheCost(): void
    {
        $entries = [
            self::entry(1, '2025-08-15', '210.00'),
            self::entry(3, '2026-08-15', '240.00'),
            self::entry(2, '2026-02-15', '0'),
        ];

        $latest = ScheduleCalculator::latest($entries);
        self::assertNotNull($latest);
        self::assertSame(3, $latest->id);
        self::assertSame('240.00', $latest->data->cost);
        self::assertNull(ScheduleCalculator::latest([]));
        self::assertSame('240.000', ForecastCalculator::cost('240.00', 'GBP')?->toDecimal(3));
        self::assertNull(ForecastCalculator::cost('0.000', 'GBP'), '0 is not a price');
        self::assertNull(ForecastCalculator::cost(null, 'GBP'));
    }

    public function testTotalsPerCurrencyAreAtLeastWithAnUnknownCost(): void
    {
        $golf = self::sources(
            schedules: [
                self::schedule(months: 6, doneOn: '2026-08-15', cost: '240.00'),
                self::schedule(months: 6, doneOn: '2026-01-01', cost: '90.00', id: 2),
            ],
            reminders: [self::manual(1, 'Fit the roof bars', '2027-06-01')],
        );
        $ducato = self::sources(
            documents: [self::document(ComplianceType::Insurance, start: '2025-12-01', expiry: '2026-11-30', cost: '610.00')],
            vehicle: self::vehicle(2, 'Ducato'),
            currency: 'EUR',
        );
        $forecast = self::forecast($golf, $ducato);

        self::assertCount(2, $forecast->totals);
        [$gbp, $eur] = $forecast->totals;
        self::assertSame('GBP', $gbp->currency);
        self::assertSame('570.000', $gbp->planned()->toDecimal(3), '240 twice and the overdue 90');
        self::assertSame('90.000', $gbp->months[0]->planned->toDecimal(3), 'overdue counts in this month');
        self::assertSame(1, $gbp->unknown());
        self::assertTrue($gbp->isAtLeast());
        self::assertSame('EUR', $eur->currency);
        self::assertSame('610.000', $eur->total()->toDecimal(3));
        self::assertFalse($eur->isAtLeast());
        self::assertSame('610.000', $eur->months[1]->planned->toDecimal(3), 'November');
    }

    public function testUndatedItemsAreInNoTotal(): void
    {
        $forecast = self::forecast(self::sources(
            schedules: [self::schedule(miles: 5000, doneKm: '40000', currentKm: '41000', kmPerDay: null, cost: '300.00')],
            currentKm: '41000',
            kmPerDay: null,
        ));

        self::assertCount(1, $forecast->undated);
        self::assertSame([], $forecast->totals, 'it may fall outside the 12 months');
    }

    // --- Fuel --------------------------------------------------------------

    public function testTheFuelWorkedExample(): void
    {
        // 30 mi a day; £150 of fuel over 1,000 mi in the last 12 months (£0.15/mi).
        $rate = self::rate([self::fill('2025-11-01T12:00:00Z', '100.00'), self::fill('2026-06-01T12:00:00Z', '50.00')]);
        self::assertSame(FuelRateStatus::Ready, $rate->status);

        $forecast = self::forecast(self::sources(kmPerDay: 30 * DistanceUnit::KM_PER_MILE, fuel: $rate));
        $estimate = $forecast->fuel[0];

        self::assertTrue($estimate->isReady());
        self::assertSame('139.50', $estimate->months[0]->toDecimal(2), 'about £139.50 for a 31-day month');
        self::assertSame('135.00', $estimate->months[1]->toDecimal(2), '30 days of November');
        self::assertSame('139.50', $forecast->totals[0]->months[0]->fuel->toDecimal(2));
        self::assertTrue($forecast->totals[0]->hasFuel);
        self::assertFalse($forecast->isEmpty());
    }

    public function testUnderNinetyDaysOfFillUpsIsNotEnoughYet(): void
    {
        $rate = self::rate([self::fill('2026-08-01T12:00:00Z', '100.00')]);
        self::assertSame(FuelRateStatus::NotEnoughFillUps, $rate->status);

        $forecast = self::forecast(self::sources(kmPerDay: 48.0, fuel: $rate));
        self::assertFalse($forecast->fuel[0]->isReady());
        self::assertNull($forecast->fuel[0]->total());
        self::assertSame([], $forecast->totals, 'nothing to total');

        self::assertSame(FuelRateStatus::NotEnoughFillUps, self::rate([])->status);
    }

    public function testWithoutAProjectionThereIsNoEstimate(): void
    {
        $rate = self::rate([self::fill('2025-11-01T12:00:00Z', '150.00')]);
        $forecast = self::forecast(self::sources(kmPerDay: null, fuel: $rate));

        self::assertSame(FuelRateStatus::NotEnoughMileage, $forecast->fuel[0]->status);
    }

    public function testWithFuelOffThereIsNoEstimate(): void
    {
        $forecast = self::forecast(self::sources(kmPerDay: 48.0, fuel: null));

        self::assertSame([], $forecast->fuel);
        self::assertTrue($forecast->isEmpty());
    }

    // --- Builders ----------------------------------------------------------

    private static function forecast(VehicleSources ...$sources): Forecast
    {
        return ForecastCalculator::forecast(array_values($sources), self::date(self::TODAY));
    }

    /**
     * @param list<ScheduleDue> $schedules
     * @param list<ComplianceDocument> $documents
     * @param list<TyreDue> $tyres
     * @param list<Reminder> $reminders
     */
    private static function sources(
        array $schedules = [],
        array $documents = [],
        array $tyres = [],
        array $reminders = [],
        ?string $currentKm = null,
        ?float $kmPerDay = self::THOUSAND_MILES_A_MONTH,
        ?FuelRate $fuel = null,
        ?Vehicle $vehicle = null,
        string $currency = 'GBP',
    ): VehicleSources {
        return new VehicleSources(
            $vehicle ?? self::vehicle(1, 'Golf'),
            $currency,
            $schedules,
            $documents,
            $tyres,
            $reminders,
            $currentKm,
            $kmPerDay,
            $fuel,
        );
    }

    /**
     * A schedule's state today, judged as ScheduleService::states() does.
     */
    private static function schedule(
        ?int $months = null,
        ?int $miles = null,
        ?string $km = null,
        ?string $doneOn = null,
        ?string $doneKm = null,
        ?string $currentKm = null,
        ?float $kmPerDay = self::THOUSAND_MILES_A_MONTH,
        ?string $cost = null,
        int $id = 1,
        string $today = self::TODAY,
    ): ScheduleDue {
        $intervalKm = $km ?? ($miles === null ? null : self::miles($miles));
        $data = new MaintenanceScheduleData(
            MaintenanceCategory::Service,
            'Annual service',
            $intervalKm,
            $months,
            $doneOn === null ? null : self::date($doneOn),
            $doneKm,
        );
        $lastDone = new DonePoint($data->baselineDoneOn, $data->baselineDoneKm);
        $next = ScheduleCalculator::nextDue($lastDone, $intervalKm, $months);
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $schedule = new MaintenanceSchedule($id, 1, $data, $lastDone, $next, $now, $now);

        return new ScheduleDue(
            new ScheduleState($schedule, DueState::evaluate($next, self::date($today), $currentKm, $kmPerDay)),
            ForecastCalculator::cost($cost, 'GBP'),
        );
    }

    private static function document(ComplianceType $type, ?string $start, string $expiry, string $cost = '0'): ComplianceDocument
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new ComplianceDocument(1, 1, new ComplianceDocumentData(
            $type,
            startOn: $start === null ? null : self::date($start),
            expiryOn: self::date($expiry),
            cost: $cost,
        ), $now, $now);
    }

    private static function manual(int $id, string $title, ?string $dueOn): Reminder
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new Reminder(
            $id,
            1,
            ReminderSource::Manual,
            null,
            null,
            null,
            $title,
            null,
            $dueOn === null ? null : self::date($dueOn),
            null,
            7,
            ReminderStatus::Upcoming,
            null,
            [],
            null,
            null,
            $now,
            $now,
        );
    }

    private static function entry(int $id, string $on, string $cost): MaintenanceEntry
    {
        $data = new MaintenanceEntryData(self::date($on), MaintenanceCategory::Service, 'Service', $cost, scheduleId: 1);

        return new MaintenanceEntry($id, 1, $data, self::date($on), self::date($on));
    }

    /**
     * @param list<CostItem> $fills
     */
    private static function rate(array $fills): FuelRate
    {
        $readings = [
            self::reading(1, '10000', '2025-11-01T12:00:00Z'),
            self::reading(2, Decimal::add('10000', self::miles(1000)), '2026-09-30T12:00:00Z'),
        ];
        $period = ReportPeriod::preset(ReportRange::TwelveMonths, self::date(self::TODAY));

        return FuelRate::of($fills, $readings, $period, new DateTimeZone(self::LONDON));
    }

    private static function fill(string $utc, string $total): CostItem
    {
        $at = new DateTimeImmutable($utc);
        $entry = new FuelEntry(1, 1, new FuelEntryData($at, '10000.000', Fuel::Petrol, '40.000', '1.500000', $total), $at, $at);

        return CostItem::fromFuel($entry, self::vehicle(1, 'Golf'), 'GBP', new DateTimeZone(self::LONDON));
    }

    private static function reading(int $id, string $km, string $utc): OdometerReading
    {
        $at = new DateTimeImmutable($utc);

        return new OdometerReading($id, 1, $km, $at, OdometerSource::Manual, null, null, $at, $at);
    }

    private static function vehicle(int $id, string $model): Vehicle
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new Vehicle(
            $id,
            1,
            new VehicleData(VehicleType::Car, 'Volkswagen', $model, FuelType::Petrol),
            VehicleStatus::Active,
            null,
            null,
            null,
            $now,
            $now,
        );
    }

    private static function miles(int $miles): string
    {
        return Decimal::multiply((string) $miles, DistanceUnit::KM_PER_MILE_DECIMAL, 3);
    }

    /**
     * @param list<ForecastItem> $items
     * @return list<string|null>
     */
    private static function titles(array $items): array
    {
        return array_map(static fn (ForecastItem $i): ?string => $i->title, $items);
    }

    /**
     * @return list<string> the dated items' days, in order
     */
    private static function dates(Forecast $forecast): array
    {
        $dates = [];
        foreach ($forecast->months as $month) {
            foreach ($month->items as $item) {
                $dates[] = $item->dueOn?->format('Y-m-d') ?? '';
            }
        }

        return $dates;
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
