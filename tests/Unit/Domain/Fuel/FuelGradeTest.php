<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Domain\Fuel;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelChoice;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Fuel\FuelPicker;
use Logbook\Service\Fuel\FuelPickerGroup;
use Logbook\Service\Fuel\FuelPickerOption;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fuel grades (spec.md §7.3): every code's family, the picker's values and
 * its grouping by the owner's region.
 */
final class FuelGradeTest extends TestCase
{
    /** Every released code and its family: codes never change. */
    private const array FAMILIES = [
        'e10_95' => 'petrol', 'e5_95' => 'petrol', 'e5_97' => 'petrol', 'e5_98' => 'petrol',
        'e10_98' => 'petrol', 'e5_99' => 'petrol', 'e0' => 'petrol', 'e85' => 'petrol',
        'e15' => 'petrol', 'e20' => 'petrol', 'aki_87' => 'petrol', 'aki_89' => 'petrol', 'aki_91' => 'petrol',
        'b7' => 'diesel', 'b7_premium' => 'diesel', 'b10' => 'diesel', 'b20' => 'diesel', 'b100' => 'diesel',
        'xtl' => 'diesel',
        'home' => 'ev', 'ac' => 'ev', 'dc' => 'ev', 'dc_rapid' => 'ev', 'dc_ultra' => 'ev',
    ];

    public function testEveryCodeBelongsToItsFamily(): void
    {
        $actual = [];
        foreach (FuelGrade::cases() as $grade) {
            $actual[$grade->value] = $grade->family()->value;
        }

        self::assertSame(self::FAMILIES, $actual);
        foreach (FuelGrade::cases() as $grade) {
            self::assertLessThanOrEqual(20, strlen($grade->value), 'fits the grade column');
        }
    }

    public function testLpgAndOtherHaveNoGrades(): void
    {
        self::assertSame([], FuelGrade::forFamily(Fuel::Lpg));
        self::assertSame([], FuelGrade::forFamily(Fuel::Other));
        self::assertSame(
            [FuelGrade::Home, FuelGrade::Ac, FuelGrade::Dc, FuelGrade::DcRapid, FuelGrade::DcUltra],
            FuelGrade::forFamily(Fuel::Electricity),
        );
        self::assertNull(FuelGrade::defaultFamilyFor(FuelType::Lpg));
        self::assertSame(Fuel::Petrol, FuelGrade::defaultFamilyFor(FuelType::Hybrid));
        self::assertSame(Fuel::Electricity, FuelGrade::defaultFamilyFor(FuelType::Electric));
    }

    public function testAGradeOfAnotherFamilyCannotBeStored(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FuelEntryData(new DateTimeImmutable(), '0', Fuel::Petrol, '1', '1', '1', grade: FuelGrade::B7);
    }

    /**
     * @return iterable<string, array{string, ?Fuel, ?FuelGrade}>
     */
    public static function pickerValues(): iterable
    {
        yield 'family only' => ['petrol', Fuel::Petrol, null];
        yield 'petrol grade' => ['petrol:e5_97', Fuel::Petrol, FuelGrade::E5_97];
        yield 'charging type' => ['ev:dc_rapid', Fuel::Electricity, FuelGrade::DcRapid];
        yield 'lpg' => ['lpg', Fuel::Lpg, null];
        yield 'garbage' => ['unleaded', null, null];
        yield 'unknown grade' => ['petrol:super', null, null];
        yield 'grade of another family' => ['petrol:b7', null, null];
        yield 'extra part' => ['petrol:e10_95:x', null, null];
        yield 'empty' => ['', null, null];
    }

    #[DataProvider('pickerValues')]
    public function testPickerValuesParse(string $value, ?Fuel $fuel, ?FuelGrade $grade): void
    {
        $choice = FuelChoice::fromValue($value);

        self::assertSame($fuel, $choice?->fuel);
        self::assertSame($grade, $choice?->grade);
        if ($choice !== null) {
            self::assertSame($value, $choice->value(), 'round-trips');
        }
    }

    /**
     * @return iterable<string, array{string, ?string, bool}>
     */
    public static function regions(): iterable
    {
        yield 'UK owner' => ['en_GB', 'GB', false];
        yield 'US owner' => ['en_US', 'US', true];
        yield 'German owner' => ['de_DE', 'DE', false];
        yield 'no region' => ['de', null, false];
    }

    #[DataProvider('regions')]
    public function testRegionalGradesAreMainOnlyInTheirRegion(string $locale, ?string $region, bool $akiIsMain): void
    {
        self::assertSame($region, FuelPicker::region($locale));

        $groups = self::picker(self::vehicle(FuelType::Petrol), $locale);
        $petrol = self::values(self::group($groups, 'fuel.fuel.petrol'));
        $more = self::values(self::group($groups, 'fuel.picker.more_grades'));

        self::assertSame('petrol', $petrol[0], 'grade not recorded comes first');
        self::assertContains('petrol:e10_95', $petrol);
        self::assertContains('petrol:e0', $petrol);
        self::assertSame($akiIsMain, in_array('petrol:aki_87', $petrol, true));
        self::assertSame(!$akiIsMain, in_array('petrol:aki_87', $more, true), 'always reachable under More grades');
        self::assertContains('petrol:e20', $more, 'India only');
        self::assertNotContains('petrol:e20', $petrol);
    }

    public function testFamiliesThatFitTheVehicleComeFirst(): void
    {
        $hybrid = self::picker(self::vehicle(FuelType::Hybrid), 'en_GB');
        self::assertSame(
            ['fuel.fuel.petrol', 'fuel.fuel.ev', 'fuel.picker.other_fuels', 'fuel.picker.more_grades'],
            array_map(static fn (FuelPickerGroup $g): string => $g->labelKey, $hybrid),
        );
        self::assertSame(
            ['ev', 'ev:home', 'ev:ac', 'ev:dc', 'ev:dc_rapid', 'ev:dc_ultra'],
            self::values(self::group($hybrid, 'fuel.fuel.ev')),
        );
        $others = self::values(self::group($hybrid, 'fuel.picker.other_fuels'));
        self::assertContains('diesel:b7', $others);
        self::assertContains('lpg', $others);
        self::assertContains('other', $others);
        self::assertNotContains('petrol', $others);

        $ev = self::picker(self::vehicle(FuelType::Electric), 'en_GB');
        self::assertSame('fuel.fuel.ev', $ev[0]->labelKey, 'an EV starts with charging');
        self::assertContains('petrol', self::values(self::group($ev, 'fuel.picker.other_fuels')));
    }

    public function testTheCurrentValueIsSelectedOnce(): void
    {
        $groups = self::picker(self::vehicle(FuelType::Petrol), 'en_GB', 'petrol:e5_97');
        $selected = [];
        foreach ($groups as $group) {
            foreach ($group->options as $option) {
                if ($option->selected) {
                    $selected[] = $option->value();
                }
            }
        }

        self::assertSame(['petrol:e5_97'], $selected);
    }

    /**
     * @return list<FuelPickerGroup>
     */
    private static function picker(Vehicle $vehicle, string $locale, string $selected = 'petrol'): array
    {
        return FuelPicker::groups($vehicle, [], $locale, new DateTimeImmutable('2026-09-28'), $selected);
    }

    /**
     * @param list<FuelPickerGroup> $groups
     */
    private static function group(array $groups, string $labelKey): FuelPickerGroup
    {
        foreach ($groups as $group) {
            if ($group->labelKey === $labelKey) {
                return $group;
            }
        }
        self::fail('No group ' . $labelKey);
    }

    /**
     * @return list<string>
     */
    private static function values(FuelPickerGroup $group): array
    {
        return array_map(static fn (FuelPickerOption $o): string => $o->value(), $group->options);
    }

    private static function vehicle(FuelType $type): Vehicle
    {
        $now = new DateTimeImmutable('2026-09-28');

        return new Vehicle(
            1,
            1,
            new VehicleData(VehicleType::Car, 'Make', 'Model', $type),
            VehicleStatus::Active,
            null,
            null,
            null,
            $now,
            $now,
        );
    }
}
