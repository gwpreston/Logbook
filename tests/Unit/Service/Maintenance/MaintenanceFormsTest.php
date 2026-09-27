<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Maintenance;

use DateTimeImmutable;
use Logbook\Domain\Maintenance\DonePoint;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Maintenance\NextDue;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceScheduleForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

final class MaintenanceFormsTest extends TestCase
{
    private const array ENTRY = [
        'performed_on' => '2026-09-01',
        'odometer' => '',
        'category' => 'tyres',
        'title' => ' Two front tyres ',
        'cost' => '',
        'vendor' => '',
        'description' => '',
        'schedule' => '',
    ];

    public function testAZeroCostEntryIsValid(): void
    {
        $data = MaintenanceEntryForm::parse(['cost' => '0'] + self::ENTRY, self::uk(), []);

        self::assertInstanceOf(MaintenanceEntryData::class, $data);
        self::assertSame('0.000', $data->cost);
    }

    public function testABlankCostMeansNothingWasPaid(): void
    {
        $data = MaintenanceEntryForm::parse(self::ENTRY, self::uk(), []);

        self::assertInstanceOf(MaintenanceEntryData::class, $data);
        self::assertSame('0.000', $data->cost);
        self::assertSame('Two front tyres', $data->title);
        self::assertSame(MaintenanceCategory::Tyres, $data->category);
        self::assertSame('2026-09-01', $data->performedOn->format('Y-m-d'));
        self::assertNull($data->odometerKm, 'the odometer is optional');
        self::assertNull($data->vendor);
        self::assertNull($data->scheduleId);
    }

    public function testTheOdometerIsTypedInTheUsersUnitAndComesBackUnchanged(): void
    {
        $data = MaintenanceEntryForm::parse(['odometer' => '30000', 'cost' => '249.99'] + self::ENTRY, self::uk(), []);

        self::assertInstanceOf(MaintenanceEntryData::class, $data);
        self::assertSame('48280.320', $data->odometerKm, '30,000 mi in km');
        self::assertSame('249.990', $data->cost);

        $entry = new MaintenanceEntry(1, 1, $data, new DateTimeImmutable(), new DateTimeImmutable());
        $values = MaintenanceEntryForm::values($entry, self::uk());
        self::assertSame('30000', $values['odometer']);
        self::assertSame('249.99', $values['cost']);
        self::assertSame('2026-09-01', $values['performed_on']);
    }

    public function testRejectsGenuinelyInvalidInput(): void
    {
        $errors = MaintenanceEntryForm::parse([
            'performed_on' => '2026-02-30',
            'category' => 'spaceship',
            'title' => '',
            'cost' => '-5',
            'odometer' => 'lots',
        ] + self::ENTRY, self::uk(), []);

        self::assertInstanceOf(ValidationErrors::class, $errors);
        foreach (['performed_on', 'category', 'title', 'cost', 'odometer'] as $field) {
            self::assertTrue($errors->has($field), $field);
        }
    }

    public function testAnEntryMayOnlyCompleteThisVehiclesSchedules(): void
    {
        $ok = MaintenanceEntryForm::parse(['schedule' => '7'] + self::ENTRY, self::uk(), [3, 7]);
        self::assertInstanceOf(MaintenanceEntryData::class, $ok);
        self::assertSame(7, $ok->scheduleId);

        $foreign = MaintenanceEntryForm::parse(['schedule' => '9'] + self::ENTRY, self::uk(), [3, 7]);
        self::assertInstanceOf(ValidationErrors::class, $foreign);
        self::assertTrue($foreign->has('schedule'));
    }

    public function testDefaultsFromASchedule(): void
    {
        $data = new MaintenanceScheduleData(MaintenanceCategory::Oil, 'Oil and filter', null, 12);
        $schedule = self::schedule(4, $data);
        $today = new DateTimeImmutable('2026-09-27T00:00:00Z');

        self::assertSame([
            'performed_on' => '2026-09-27',
            'category' => 'oil',
            'title' => 'Oil and filter',
            'schedule' => '4',
        ], MaintenanceEntryForm::defaults($today, $schedule));
    }

    public function testAScheduleNeedsAtLeastOneInterval(): void
    {
        $base = ['title' => 'Service', 'category' => 'service', 'interval_distance' => '', 'interval_months' => ''];

        $none = MaintenanceScheduleForm::parse($base, self::uk());
        self::assertInstanceOf(ValidationErrors::class, $none);
        self::assertSame('maintenance.schedule.need_interval', $none->all()['interval_distance']['key']);

        $zero = MaintenanceScheduleForm::parse(['interval_distance' => '0'] + $base, self::uk());
        self::assertInstanceOf(ValidationErrors::class, $zero);
        self::assertSame('validation.positive', $zero->all()['interval_distance']['key']);

        $months = MaintenanceScheduleForm::parse(['interval_months' => '0'] + $base, self::uk());
        self::assertInstanceOf(ValidationErrors::class, $months);
        self::assertTrue($months->has('interval_months'));
    }

    public function testScheduleDistancesAreStoredInKm(): void
    {
        $data = MaintenanceScheduleForm::parse([
            'title' => 'Service',
            'category' => 'service',
            'interval_distance' => '10000',
            'interval_months' => '12',
            'last_done_on' => '2026-01-15',
            'last_done_odometer' => '25000',
        ], self::uk());

        self::assertInstanceOf(MaintenanceScheduleData::class, $data);
        self::assertSame('16093.440', $data->intervalKm);
        self::assertSame(12, $data->intervalMonths);
        self::assertSame('2026-01-15', $data->baselineDoneOn?->format('Y-m-d'));
        self::assertSame('40233.600', $data->baselineDoneKm);

        $schedule = self::schedule(1, $data);
        $values = MaintenanceScheduleForm::values($schedule, self::uk());
        self::assertSame('10000', $values['interval_distance']);
        self::assertSame('25000', $values['last_done_odometer']);
    }

    private static function schedule(int $id, MaintenanceScheduleData $data): MaintenanceSchedule
    {
        $now = new DateTimeImmutable();

        return new MaintenanceSchedule($id, 1, $data, new DonePoint(), new NextDue(), $now, $now);
    }

    private static function uk(): DisplayPreferences
    {
        $preset = UnitPreset::Uk;

        return new DisplayPreferences(
            'en_GB',
            'Europe/London',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        );
    }
}
