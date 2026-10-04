<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Units;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\EconomyScale;
use Logbook\Support\Units\GasEfficiencyUnit;
use Logbook\Support\Units\VolumeUnit;
use PHPUnit\Framework\TestCase;

final class EconomyScaleTest extends TestCase
{
    public function testEachKindHasItsOwnUnit(): void
    {
        $liquid = EconomyScale::of(EnergyKind::Liquid, DistanceUnit::Mile, ConsumptionUnit::MpgUk, VolumeUnit::UkGallon);
        $electric = EconomyScale::of(EnergyKind::Electric, DistanceUnit::Mile, ConsumptionUnit::MpgUk, VolumeUnit::UkGallon);
        $gas = EconomyScale::of(EnergyKind::Gas, DistanceUnit::Mile, ConsumptionUnit::MpgUk, VolumeUnit::UkGallon);

        self::assertSame(['mpg_uk', 'mi_per_kwh', 'mi_per_kg'], [$liquid->code(), $electric->code(), $gas->code()]);
        self::assertSame(['gal_uk', 'kwh', 'kg'], [$liquid->quantityCode(), $electric->quantityCode(), $gas->quantityCode()]);
        self::assertTrue($gas->higherIsBetter());

        // 400 km on 20 L is 56.5 mpg (UK); on 16 kg it is 15.5 mi/kg.
        self::assertEqualsWithDelta(56.5, (float) $liquid->value(400, 20), 0.05);
        self::assertEqualsWithDelta(15.53, (float) $gas->value(400, 16), 0.005);
        self::assertEqualsWithDelta(4.14, (float) $electric->value(400, 60), 0.005);
        self::assertNull($gas->value(0, 16));

        // A price per litre follows the gallon; per kWh and per kg never convert.
        self::assertEqualsWithDelta(6.81914, $liquid->pricePerShownUnit(1.5), 1e-5);
        self::assertSame(1.5, $gas->pricePerShownUnit(1.5));
        self::assertSame(1.5, $electric->pricePerShownUnit(1.5));
    }

    public function testKilometreUsersSeeKgPer100Km(): void
    {
        $gas = EconomyScale::of(EnergyKind::Gas, DistanceUnit::Kilometre, ConsumptionUnit::LitresPer100Km, VolumeUnit::Litre);

        self::assertSame('kg_per_100km', $gas->code());
        self::assertEqualsWithDelta(4.0, (float) $gas->value(400, 16), 1e-9);
        self::assertFalse($gas->higherIsBetter());
        self::assertNull(GasEfficiencyUnit::KgPer100Km->fromDistanceAndMass(400, 0));
    }

    public function testACngCarIsMeasuredInKgAndFillsWithCngOrPetrol(): void
    {
        self::assertSame(EnergyKind::Gas, FuelType::Cng->primaryKind());
        self::assertSame(EnergyKind::Electric, FuelType::Electric->primaryKind());
        self::assertSame(EnergyKind::Liquid, FuelType::Phev->primaryKind());
        self::assertSame('vehicle.field.capacity_gas', FuelType::Cng->capacityLabelKey());
        self::assertFalse(EnergyKind::Gas->followsVolumeUnit());
        self::assertTrue(EnergyKind::Liquid->followsVolumeUnit());
    }
}
