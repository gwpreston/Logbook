<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Vehicle;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Service\Vehicle\NewVehicle;
use Logbook\Service\Vehicle\PurchaseMileage;
use Logbook\Service\Vehicle\PurchaseMileageNeedsDate;
use Logbook\Service\Vehicle\VehicleEdit;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

/**
 * *Bought from* and *Mileage when bought* on the vehicle form (spec.md
 * §7.1, Phase 33.3): the seller trimmed and at most 100 characters, the
 * mileage in the owner's unit, ≥ 0 with 0 valid, and the date rules.
 */
final class PurchaseMileageFormTest extends TestCase
{
    private const array GOLF = [
        'type' => 'car', 'make' => 'Volkswagen', 'model' => 'Golf', 'fuel_type' => 'petrol',
        'purchase_date' => '2021-05-01', 'purchase_seller' => ' Halden Motors ', 'purchase_odometer' => '30000',
    ];

    public function testTheSellerAndTheMileageInKm(): void
    {
        $edit = $this->parseEdit(self::GOLF);
        self::assertSame('Halden Motors', $edit->data->purchaseSeller);
        self::assertSame('48280.320', $edit->purchaseMileage->km, '30,000 mi');

        $new = VehicleForm::parseNew(self::GOLF, self::miles(), self::today(), false);
        self::assertInstanceOf(NewVehicle::class, $new);
        self::assertSame('48280.320', $new->purchaseKm);
        self::assertSame('Halden Motors', $new->data->purchaseSeller);
    }

    public function testZeroIsValidAndBlankIsNone(): void
    {
        self::assertSame('0.000', $this->parseEdit(['purchase_odometer' => '0'] + self::GOLF)->purchaseMileage->km);
        $blank = $this->parseEdit(['purchase_odometer' => '', 'purchase_seller' => '  '] + self::GOLF);
        self::assertNull($blank->purchaseMileage->km);
        self::assertNull($blank->data->purchaseSeller);
    }

    public function testANegativeMileageOrALongSellerIsRefused(): void
    {
        $errors = VehicleForm::parseEdit(
            ['purchase_odometer' => '-1', 'purchase_seller' => str_repeat('a', 101)] + self::GOLF,
            self::miles(),
            self::today(),
            false,
        );
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertTrue($errors->has('purchase_odometer'));
        self::assertTrue($errors->has('purchase_seller'));
        $parsed = VehicleForm::parse(['purchase_odometer' => 'x'] + self::GOLF, self::miles(), self::today());
        self::assertInstanceOf(ValidationErrors::class, $parsed);
    }

    public function testTheEditFormShowsTheStoredValuesInTheOwnersUnit(): void
    {
        $data = $this->parseEdit(self::GOLF)->data;
        $vehicle = new Vehicle(1, 1, $data, VehicleStatus::Active, null, null, null, self::today(), self::today());

        $values = VehicleForm::values($vehicle, self::miles(), '48280.320');
        self::assertSame('Halden Motors', $values['purchase_seller']);
        self::assertSame('30000', $values['purchase_odometer']);
        self::assertSame('', VehicleForm::values($vehicle, self::miles())['purchase_odometer']);
    }

    public function testTheDateRules(): void
    {
        $on = LocalTime::parseDate('2021-05-01');
        $some = new PurchaseMileage('100');
        $none = new PurchaseMileage(null);

        self::assertNull(PurchaseMileageNeedsDate::check($on, true, $some), 'dated');
        self::assertNull(PurchaseMileageNeedsDate::check(null, false, $none), 'nothing to date');
        self::assertNull(PurchaseMileageNeedsDate::check(null, true, $none), 'cleared together');
        self::assertNull(PurchaseMileageNeedsDate::check(null, false, null), 'kept: there is none');

        $adding = PurchaseMileageNeedsDate::check(null, false, $some);
        self::assertNotNull($adding);
        self::assertSame('purchase_odometer', $adding->field());
        self::assertSame('vehicle.error.purchase_mileage_needs_date', $adding->messageKey());

        $clearing = PurchaseMileageNeedsDate::check(null, true, $some);
        self::assertNotNull($clearing);
        self::assertSame('purchase_date', $clearing->field());
        self::assertSame('vehicle.error.purchase_date_has_mileage', $clearing->messageKey());
        self::assertNotNull(PurchaseMileageNeedsDate::check(null, true, null), 'kept by a save outside the form');
    }

    /**
     * @param array<string, string> $input
     */
    private function parseEdit(array $input): VehicleEdit
    {
        $edit = VehicleForm::parseEdit($input, self::miles(), self::today(), false);
        self::assertInstanceOf(VehicleEdit::class, $edit);

        return $edit;
    }

    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC'));
    }

    private static function miles(): DisplayPreferences
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
}
