<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Valuation;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Valuation\ValuationForm;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

final class ValuationFormTest extends TestCase
{
    public function testAValidValuation(): void
    {
        $data = ValuationForm::parse(
            ['valued_on' => '2026-03-01', 'amount' => '9,800.50', 'source' => ' Auto Trader valuation ', 'notes' => ''],
            self::prefs(),
            self::date('2026-09-27'),
            self::vehicle(),
        );

        self::assertInstanceOf(VehicleValuationData::class, $data);
        self::assertSame('2026-03-01', $data->valuedOn->format('Y-m-d'));
        self::assertSame('9800.500', $data->amount);
        self::assertSame('Auto Trader valuation', $data->source);
        self::assertNull($data->notes);
    }

    public function testZeroIsAValue(): void
    {
        $data = self::parse(['valued_on' => '2026-03-01', 'amount' => '0']);

        self::assertInstanceOf(VehicleValuationData::class, $data);
        self::assertSame('0.000', $data->amount, 'a write-off or scrap value');
    }

    public function testADateAndAnAmountAreRequiredAndTheAmountIsNotNegative(): void
    {
        self::assertSame(
            ['valued_on' => 'validation.required', 'amount' => 'validation.required'],
            self::keys(['valued_on' => '', 'amount' => '']),
        );
        self::assertSame(['amount' => 'validation.min'], self::keys(['valued_on' => '2026-03-01', 'amount' => '-1']));
    }

    public function testTheDateCannotBeAfterTodayInTheOwnersTimeZone(): void
    {
        // 23:30 UTC on 27 September: already the 28th in Auckland, still the 27th in New York.
        $clock = new MutableClock(new DateTimeImmutable('2026-09-27T23:30:00Z'));
        $auckland = LocalTime::today($clock, new DateTimeZone('Pacific/Auckland'));
        $newYork = LocalTime::today($clock, new DateTimeZone('America/New_York'));
        $input = ['valued_on' => '2026-09-28', 'amount' => '5000'];

        self::assertInstanceOf(VehicleValuationData::class, self::parse($input, $auckland));
        self::assertSame(['valued_on' => 'valuation.error.future'], self::keys($input, $newYork));
    }

    public function testNotBeforeThePurchase(): void
    {
        $vehicle = self::vehicle(purchased: '2023-03-01');

        self::assertSame(
            ['valued_on' => 'valuation.error.before_purchase'],
            self::keys(['valued_on' => '2023-02-28', 'amount' => '1'], vehicle: $vehicle),
        );
        self::assertInstanceOf(
            VehicleValuationData::class,
            self::parse(['valued_on' => '2023-03-01', 'amount' => '1'], vehicle: $vehicle),
            'the day it was bought',
        );
    }

    public function testNotAfterTheSaleWhichNamesTheSaleDate(): void
    {
        $vehicle = self::vehicle(sold: '2026-03-12');

        $errors = self::parse(['valued_on' => '2026-03-13', 'amount' => '1'], vehicle: $vehicle);

        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame('valuation.error.after_sale', $errors->all()['valued_on']['key']);
        self::assertSame(['date' => '12 Mar 2026'], $errors->all()['valued_on']['params']);
        self::assertInstanceOf(
            VehicleValuationData::class,
            self::parse(['valued_on' => '2026-03-12', 'amount' => '1'], vehicle: $vehicle),
            'the day it was sold',
        );
    }

    public function testTheSourceIsAtMostOneHundredCharacters(): void
    {
        $input = ['valued_on' => '2026-03-01', 'amount' => '1'];

        self::assertSame([], self::keys($input + ['source' => str_repeat('a', 100)]));
        self::assertSame(['source' => 'validation.too_long'], self::keys($input + ['source' => str_repeat('a', 101)]));
        self::assertSame(['notes' => 'validation.too_long'], self::keys($input + ['notes' => str_repeat('a', 501)]));
    }

    public function testValuesRoundTrip(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $data = new VehicleValuationData(self::date('2026-03-01'), '9800.000', 'Dealer');
        $valuation = new VehicleValuation(1, 1, $data, $now, $now);

        self::assertSame(
            ['valued_on' => '2026-03-01', 'amount' => '9800', 'source' => 'Dealer', 'notes' => ''],
            ValuationForm::values($valuation),
        );
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string> field → message key
     */
    private static function keys(array $input, ?DateTimeImmutable $today = null, ?Vehicle $vehicle = null): array
    {
        $result = self::parse($input, $today, $vehicle);
        if (!$result instanceof ValidationErrors) {
            return [];
        }

        return array_map(static fn (array $error): string => $error['key'], $result->all());
    }

    /**
     * @param array<string, string> $input
     */
    private static function parse(
        array $input,
        ?DateTimeImmutable $today = null,
        ?Vehicle $vehicle = null,
    ): VehicleValuationData|ValidationErrors {
        return ValuationForm::parse($input, self::prefs(), $today ?? self::date('2026-09-27'), $vehicle ?? self::vehicle());
    }

    private static function vehicle(?string $purchased = null, ?string $sold = null): Vehicle
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new Vehicle(1, 1, new VehicleData(
            type: VehicleType::Car,
            make: 'Volkswagen',
            model: 'Golf',
            fuelType: FuelType::Petrol,
            purchaseDate: $purchased === null ? null : self::date($purchased),
            saleDate: $sold === null ? null : self::date($sold),
        ), VehicleStatus::Active, null, null, null, $now, $now);
    }

    private static function prefs(): DisplayPreferences
    {
        return new DisplayPreferences(
            'en_GB',
            'Europe/London',
            DistanceUnit::Mile,
            VolumeUnit::Litre,
            ConsumptionUnit::MpgUk,
            'GBP',
        );
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
