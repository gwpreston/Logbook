<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Vehicle;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Vehicle\VehicleLabels;
use Logbook\Tests\Support\WorkingWords;
use PHPUnit\Framework\TestCase;

/**
 * Telling same-named vehicles apart (spec.md §7.1, K203, #630, #631, #639).
 */
final class VehicleLabelsTest extends TestCase
{
    public function testNamesWithNoClashAreUnchanged(): void
    {
        $labels = $this->labels([
            self::vehicle(1, 'Ford', 'Fiesta', registration: 'AB12 CDE'),
            self::vehicle(2, 'Ford', 'Focus', registration: 'XY34 ZZZ'),
            self::vehicle(3, 'Ford', 'Fiesta', nickname: 'Blue'),
        ]);

        self::assertSame([1 => 'Ford Fiesta', 2 => 'Ford Focus', 3 => 'Blue'], $labels);
    }

    public function testTwoFiestasGainTheirRegistrations(): void
    {
        $labels = $this->labels([
            self::vehicle(1, 'Ford', 'Fiesta', registration: 'AB12 CDE', year: 2019),
            self::vehicle(2, 'Ford', 'Fiesta', registration: 'XY34 ZZZ', year: 2019),
        ]);

        self::assertSame([1 => 'Ford Fiesta (AB12 CDE)', 2 => 'Ford Fiesta (XY34 ZZZ)'], $labels);
    }

    public function testOneWithoutARegistrationGainsItsYear(): void
    {
        $labels = $this->labels([
            self::vehicle(1, 'Ford', 'Fiesta', registration: 'AB12 CDE'),
            self::vehicle(2, 'Ford', 'Fiesta', registration: '  ', year: 2019),
        ]);

        self::assertSame([1 => 'Ford Fiesta (AB12 CDE)', 2 => 'Ford Fiesta (2019)'], $labels);
    }

    public function testThoseStillAlikeAreNumberedOldestFirst(): void
    {
        $labels = $this->labels([
            self::vehicle(5, 'Ford', 'Fiesta', added: '2026-03-01'),
            self::vehicle(4, 'Ford', 'Fiesta', added: '2026-05-01'),
            self::vehicle(6, 'Ford', 'Fiesta', added: '2026-01-01'),
        ]);

        self::assertSame([5 => 'Ford Fiesta (2)', 4 => 'Ford Fiesta (3)', 6 => 'Ford Fiesta (1)'], $labels);
    }

    public function testTheSameYearIsNumberedAndAVehicleWithNothingKeepsItsName(): void
    {
        // Two 2019s with no registration are still alike; the third has
        // neither registration nor year and already differs from them.
        $labels = $this->labels([
            self::vehicle(1, 'Ford', 'Fiesta', year: 2019, added: '2026-02-01'),
            self::vehicle(2, 'Ford', 'Fiesta', year: 2019, added: '2026-01-01'),
            self::vehicle(3, 'Ford', 'Fiesta'),
        ]);

        self::assertSame([1 => 'Ford Fiesta (2)', 2 => 'Ford Fiesta (1)', 3 => 'Ford Fiesta'], $labels);
    }

    public function testNamesAreComparedTrimmedAndIgnoringCase(): void
    {
        $labels = $this->labels([
            self::vehicle(1, 'Ford', 'fiesta ', registration: 'AB12 CDE'),
            self::vehicle(2, 'Ford', 'Fiesta', registration: 'XY34 ZZZ'),
        ]);

        self::assertSame([1 => 'Ford fiesta (AB12 CDE)', 2 => 'Ford Fiesta (XY34 ZZZ)'], $labels);
    }

    public function testANicknameMatchingAnotherVehiclesMakeAndModelClashes(): void
    {
        $labels = $this->labels([
            self::vehicle(1, 'Vauxhall', 'Corsa', nickname: 'Ford Fiesta', registration: 'AB12 CDE'),
            self::vehicle(2, 'Ford', 'Fiesta', registration: 'XY34 ZZZ'),
        ]);

        self::assertSame([1 => 'Ford Fiesta (AB12 CDE)', 2 => 'Ford Fiesta (XY34 ZZZ)'], $labels);
    }

    public function testOnlyTheVehiclesPassedInCount(): void
    {
        // An archived Fiesta the picker doesn't list changes nothing (#631).
        $labels = $this->labels([self::vehicle(1, 'Ford', 'Fiesta', registration: 'AB12 CDE')]);

        self::assertSame([1 => 'Ford Fiesta'], $labels);
    }

    public function testWhereTheRegistrationIsShownDifferentRegistrationsKeepThePlainName(): void
    {
        $labels = $this->labels([
            self::vehicle(1, 'Ford', 'Fiesta', registration: 'AB12 CDE'),
            self::vehicle(2, 'Ford', 'Fiesta', registration: 'XY34 ZZZ'),
        ], true);

        self::assertSame([1 => 'Ford Fiesta', 2 => 'Ford Fiesta'], $labels);
    }

    public function testWhereTheRegistrationIsShownThoseWithoutOneGainTheirYearElseANumber(): void
    {
        $labels = $this->labels([
            self::vehicle(1, 'Ford', 'Fiesta', registration: 'AB12 CDE'),
            self::vehicle(2, 'Ford', 'Fiesta', year: 2019),
            self::vehicle(3, 'Ford', 'Fiesta', year: 2021),
            self::vehicle(4, 'Ford', 'Focus', added: '2026-01-01'),
            self::vehicle(5, 'Ford', 'Focus', added: '2026-02-01'),
        ], true);

        self::assertSame([
            1 => 'Ford Fiesta',
            2 => 'Ford Fiesta (2019)',
            3 => 'Ford Fiesta (2021)',
            4 => 'Ford Focus (1)',
            5 => 'Ford Focus (2)',
        ], $labels);
    }

    public function testTheWrapperIsTranslated(): void
    {
        $labels = (new VehicleLabels(WorkingWords::translator('de')))->labels([
            self::vehicle(1, 'Ford', 'Fiesta', registration: 'AB12 CDE'),
            self::vehicle(2, 'Ford', 'Fiesta'),
        ]);

        self::assertSame([1 => 'Ford Fiesta (AB12 CDE)', 2 => 'Ford Fiesta'], $labels);
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return array<int, string>
     */
    private function labels(array $vehicles, bool $registrationShown = false): array
    {
        return (new VehicleLabels(WorkingWords::translator()))->labels($vehicles, $registrationShown);
    }

    private static function vehicle(
        int $id,
        string $make,
        string $model,
        ?string $nickname = null,
        ?string $registration = null,
        ?int $year = null,
        string $added = '2026-01-01',
    ): Vehicle {
        $at = new DateTimeImmutable($added);

        return new Vehicle(
            $id,
            1,
            new VehicleData(VehicleType::Car, $make, $model, FuelType::Petrol, $nickname, $year, $registration),
            VehicleStatus::Active,
            null,
            null,
            null,
            $at,
            $at,
        );
    }
}
