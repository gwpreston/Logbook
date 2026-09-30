<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Vehicle;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Service\Vehicle\NewVehicle;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

/**
 * *First MOT due* on the vehicle form (spec.md §7.1): the add form fills a
 * blank field from the suggestion without JS, the edit form never does,
 * and off the form the stored date is kept whatever is posted.
 */
final class FirstInspectionFormTest extends TestCase
{
    /** A two-year-old car: its GB first MOT is on 14 Jun 2027. */
    private const array CAR = [
        'type' => 'car', 'make' => 'Kia', 'model' => 'EV6', 'fuel_type' => 'ev',
        'first_registered_on' => '2024-06-14', 'first_inspection_due_on' => '',
    ];

    public function testAddWithABlankFieldFillsTheSuggestion(): void
    {
        $new = $this->parseNew(self::CAR);

        self::assertSame('2027-06-14', $new->data->firstInspectionDueOn?->format('Y-m-d'));
        self::assertSame('2027-06-14', $new->suggestedFirstInspection?->format('Y-m-d'), 'so the flash can say so');
    }

    public function testAnExplicitDateIsKept(): void
    {
        $new = $this->parseNew(['first_inspection_due_on' => '2028-06-14'] + self::CAR);

        self::assertSame('2028-06-14', $new->data->firstInspectionDueOn?->format('Y-m-d'));
        self::assertNull($new->suggestedFirstInspection);
    }

    public function testABlankTheScriptLeftIsTheOwnersChoice(): void
    {
        $new = $this->parseNew([VehicleForm::FIRST_INSPECTION_JS => '1'] + self::CAR);

        self::assertNull($new->data->firstInspectionDueOn, 'cleared with JS on: nothing filled in');
        self::assertNull($new->suggestedFirstInspection);
    }

    public function testNoSuggestionForAPastDateAnotherRegionOrNoFirstRegistration(): void
    {
        $passed = $this->parseNew(['first_registered_on' => '2021-06-14'] + self::CAR);
        self::assertNull($passed->data->firstInspectionDueOn, 'had its first MOT');
        self::assertNull($this->parseNew(self::CAR, 'en_US')->data->firstInspectionDueOn);
        self::assertNull($this->parseNew(self::CAR, 'en')->data->firstInspectionDueOn, 'no region');
        self::assertNull($this->parseNew(['first_registered_on' => ''] + self::CAR)->data->firstInspectionDueOn);
    }

    public function testFourYearsInFrance(): void
    {
        self::assertSame('2028-06-14', $this->parseNew(self::CAR, 'fr_FR')->data->firstInspectionDueOn?->format('Y-m-d'));
    }

    public function testWithoutTheFieldOnTheFormAddFillsNothing(): void
    {
        $new = VehicleForm::parseNew(self::CAR, self::prefs(), self::today(), false);

        self::assertInstanceOf(NewVehicle::class, $new);
        self::assertNull($new->data->firstInspectionDueOn, 'compliance off');
    }

    public function testEditWithABlankFieldStaysBlank(): void
    {
        $data = VehicleForm::parse(self::CAR, self::prefs(), self::today());

        self::assertInstanceOf(VehicleData::class, $data);
        self::assertNull($data->firstInspectionDueOn, 'clearing it sticks');
    }

    public function testOffTheFormEditKeepsTheStoredDateWhateverIsPosted(): void
    {
        $kept = new DateTimeImmutable('2027-06-14', new DateTimeZone('UTC'));
        $posted = [
            'blank' => self::CAR,
            'another date' => ['first_inspection_due_on' => '2030-01-01'] + self::CAR,
            'no field' => array_diff_key(self::CAR, ['first_inspection_due_on' => 1]),
        ];
        foreach ($posted as $input) {
            $data = VehicleForm::parse($input, self::prefs(), self::today(), false, $kept);
            self::assertInstanceOf(VehicleData::class, $data);
            self::assertEquals($kept, $data->firstInspectionDueOn);
        }
    }

    public function testNotBeforeFirstRegistration(): void
    {
        $errors = VehicleForm::parse(['first_inspection_due_on' => '2024-06-13'] + self::CAR, self::prefs(), self::today());

        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame(['first_inspection_due_on'], array_keys($errors->all()));
        self::assertSame('vehicle.first_inspection_before_registration', $errors->all()['first_inspection_due_on']['key']);
    }

    public function testTheSameDayAsFirstRegistrationAndAPastDateAreAccepted(): void
    {
        $data = VehicleForm::parse(['first_inspection_due_on' => '2024-06-14'] + self::CAR, self::prefs(), self::today());
        self::assertInstanceOf(VehicleData::class, $data);

        $input = ['first_registered_on' => '', 'first_inspection_due_on' => '2020-01-01'] + self::CAR;
        $data = VehicleForm::parse($input, self::prefs(), self::today());
        self::assertInstanceOf(VehicleData::class, $data, 'a past date typed by the owner is theirs: it shows as overdue');
    }

    public function testEditValuesRoundTrip(): void
    {
        $data = VehicleForm::parse(['first_inspection_due_on' => '2027-06-14'] + self::CAR, self::prefs(), self::today());
        self::assertInstanceOf(VehicleData::class, $data);
        $same = $data->withFirstInspectionDueOn($data->firstInspectionDueOn);
        self::assertSame('2027-06-14', $same->firstInspectionDueOn?->format('Y-m-d'));
        self::assertNull($data->withFirstInspectionDueOn(null)->firstInspectionDueOn);
        $cleared = $data->withFirstInspectionDueOn(null);
        self::assertSame('2024-06-14', $cleared->firstRegisteredOn?->format('Y-m-d'), 'everything else is kept');
    }

    /**
     * @param array<string, string> $input
     */
    private function parseNew(array $input, string $locale = 'en_GB'): NewVehicle
    {
        $new = VehicleForm::parseNew($input, self::prefs($locale), self::today());
        $problems = $new instanceof ValidationErrors ? (string) json_encode($new->all()) : '';
        self::assertInstanceOf(NewVehicle::class, $new, $problems);

        return $new;
    }

    private static function prefs(string $locale = 'en_GB'): DisplayPreferences
    {
        return new DisplayPreferences(
            $locale,
            'Europe/London',
            DistanceUnit::Mile,
            VolumeUnit::Litre,
            ConsumptionUnit::MpgUk,
            'GBP',
        );
    }

    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-30', new DateTimeZone('UTC'));
    }
}
