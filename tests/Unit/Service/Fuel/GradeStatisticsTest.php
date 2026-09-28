<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Fuel;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Fuel\FuelEconomy;
use Logbook\Service\Fuel\GradeStatistics;
use Logbook\Service\Fuel\GradeSummary;
use PHPUnit\Framework\TestCase;

/**
 * Worked examples for figures by grade (spec.md §7.3). Economy is
 * attributed to the fuel that was burned: in a full-to-full segment A → B
 * the vehicle ran on what went in at A (and any partials inside), never on
 * what went in at B.
 */
final class GradeStatisticsTest extends TestCase
{
    private int $nextId = 1;

    public function testASegmentBetweenTwoFillsOfOneGradeIsThatGrades(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60', FuelGrade::E10_95),
            $this->fill('1500', '35', '52.5', FuelGrade::E10_95),
        ]);

        self::assertSame(FuelGrade::E10_95, $history->fills[1]->segment?->grade);
    }

    public function testTheSegmentIsAttributedToTheOpeningFillNotTheClosingOne(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60', FuelGrade::E5_97),
            $this->fill('1500', '35', '52.5', FuelGrade::E10_95),
            $this->fill('2000', '36', '54', FuelGrade::E10_95),
        ]);

        self::assertSame(FuelGrade::E5_97, $history->fills[1]->segment?->grade, 'E5 was burned between the two');
        self::assertSame(FuelGrade::E10_95, $history->fills[2]->segment?->grade);
    }

    public function testAPartialOfAnotherGradeMixesTheSegment(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60', FuelGrade::E10_95),
            $this->fill('1200', '10', '16', FuelGrade::E5_97, partial: true),
            $this->fill('1500', '25', '37.5', FuelGrade::E10_95),
            $this->fill('1700', '10', '15', FuelGrade::E10_95, partial: true),
            $this->fill('2000', '26', '39', FuelGrade::E10_95),
        ]);

        self::assertNotNull($history->fills[2]->segment);
        self::assertNull($history->fills[2]->segment->grade, 'E10 with an E5 top-up: mixed');
        self::assertSame(FuelGrade::E10_95, $history->fills[4]->segment?->grade, 'a partial of the same grade keeps it');
    }

    public function testAnUnrecordedOpeningFillOrPartialLeavesTheSegmentUnattributed(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60'),
            $this->fill('1500', '35', '52.5', FuelGrade::E10_95),
            $this->fill('1700', '10', '15', partial: true),
            $this->fill('2000', '26', '39', FuelGrade::E10_95),
        ]);

        self::assertNull($history->fills[1]->segment?->grade, 'opened by a fill with no grade');
        self::assertNull($history->fills[3]->segment?->grade, 'a top-up with no grade');
    }

    public function testAMissedFillStillDiscardsTheOpenSegment(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60', FuelGrade::B7, Fuel::Diesel),
            $this->fill('1500', '35', '52.5', FuelGrade::B10, Fuel::Diesel, missedPrevious: true),
            $this->fill('2000', '36', '54', FuelGrade::B7, Fuel::Diesel),
        ]);

        self::assertNull($history->fills[1]->segment, 'discarded, as without grades');
        self::assertSame(FuelGrade::B10, $history->fills[2]->segment?->grade, 'measuring restarts from the B10 fill');
    }

    public function testGradesNeverChangeTheFamilyFigures(): void
    {
        $graded = [
            $this->fill('1000', '40', '60', FuelGrade::E5_97),
            $this->fill('1300', '20', '31', FuelGrade::E10_95, partial: true),
            $this->fill('1600', '22', '33', FuelGrade::E10_95),
            $this->fill('2200', '41', '61', FuelGrade::E5_97),
        ];
        $plain = array_map(static fn (FuelEntry $e): FuelEntry => new FuelEntry(
            $e->id,
            $e->vehicleId,
            new FuelEntryData(
                $e->data->filledAt,
                $e->data->odometerKm,
                $e->data->fuel,
                $e->data->volume,
                $e->data->pricePerUnit,
                $e->data->totalCost,
                $e->data->isPartial,
            ),
            $e->createdAt,
            $e->updatedAt,
        ), $graded);

        self::assertSame(self::liquidFigures($plain), self::liquidFigures($graded));
        self::assertSame(2, FuelEconomy::analyse($graded)->summary(EnergyKind::Liquid)?->segments);
    }

    /**
     * @param list<FuelEntry> $entries
     * @return list<int|string|null>
     */
    private static function liquidFigures(array $entries): array
    {
        $summary = FuelEconomy::analyse($entries)->summary(EnergyKind::Liquid);
        self::assertNotNull($summary);

        return [
            $summary->fills, $summary->totalVolume, $summary->totalCost, $summary->measuredDistanceKm,
            $summary->measuredVolume, $summary->measuredCost, $summary->segments,
            $summary->lastSegment?->distanceKm, $summary->lastSegment?->volume,
        ];
    }

    public function testEconomyByGradeNeedsTwoAttributedSegments(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60', FuelGrade::E10_95),
            $this->fill('1500', '35', '52.5', FuelGrade::E10_95),
            $this->fill('2000', '36', '54', FuelGrade::E5_97),
            $this->fill('2600', '42', '67.2', FuelGrade::E10_95),
            $this->fill('3200', '40', '60', FuelGrade::E10_95),
        ]);
        $breakdown = GradeStatistics::breakdown($history, EnergyKind::Liquid);

        $e10 = self::row($breakdown->rows, FuelGrade::E10_95);
        self::assertSame(4, $e10->fills);
        self::assertSame('157.000', $e10->volume);
        // Segments opened by an E10 fill: 1000 → 1500, 1500 → 2000 (closed
        // by the E5 fill, but E10 was burned) and 2600 → 3200: 1600 km on 111 L.
        self::assertSame(3, $e10->segments);
        self::assertSame('1600.000', $e10->measuredDistanceKm);
        self::assertSame('111.000', $e10->measuredVolume);
        self::assertTrue($e10->hasEconomy());

        $e5 = self::row($breakdown->rows, FuelGrade::E5_97);
        self::assertSame(1, $e5->segments, 'only 2000 → 2600');
        self::assertFalse($e5->hasEconomy(), 'one segment: not enough fills yet');
        self::assertSame('1.500000', $e5->averagePricePerUnit(), '54 for 36 L');
    }

    public function testPricePerGradeAndOrder(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '40', '60', FuelGrade::E5_97),
            $this->fill('1500', '35', '63', FuelGrade::E5_99),
            $this->fill('2000', '50', '70'),
            $this->fill('2500', '45', '67.5', FuelGrade::E5_97),
        ]);
        $breakdown = GradeStatistics::breakdown($history, EnergyKind::Liquid);

        self::assertSame(
            [FuelGrade::E5_97, FuelGrade::E5_99, null],
            array_map(static fn (GradeSummary $r): ?FuelGrade => $r->grade, $breakdown->rows),
            'most bought first, not recorded last',
        );
        self::assertSame('1.500000', $breakdown->rows[0]->averagePricePerUnit(), '127.50 for 85 L');
        self::assertSame('1.800000', $breakdown->rows[1]->averagePricePerUnit());
        self::assertTrue($breakdown->hasGrades());
    }

    public function testCostPerKwhAndShareOfEnergyPerChargingType(): void
    {
        $history = FuelEconomy::analyse([
            $this->fill('1000', '50', '3.75', FuelGrade::Home, Fuel::Electricity),
            $this->fill('1300', '30', '0', FuelGrade::Ac, Fuel::Electricity),        // free, at a hotel
            $this->fill('1600', '20', '15.80', FuelGrade::DcRapid, Fuel::Electricity),
            $this->fill('1900', '50', '3.75', FuelGrade::Home, Fuel::Electricity),
        ]);
        $breakdown = GradeStatistics::breakdown($history, EnergyKind::Electric);

        $home = self::row($breakdown->rows, FuelGrade::Home);
        $ac = self::row($breakdown->rows, FuelGrade::Ac);
        $rapid = self::row($breakdown->rows, FuelGrade::DcRapid);
        self::assertSame('0.075000', $home->averagePricePerUnit(), '7.5p per kWh');
        self::assertSame('0.000000', $ac->averagePricePerUnit(), 'a free charge costs 0 per kWh');
        self::assertSame('0.790000', $rapid->averagePricePerUnit());
        // 150 kWh in all: home 100 (2/3), AC 30 (1/5), rapid 20 (2/15).
        self::assertEqualsWithDelta(0.666667, $home->share(), 1e-6);
        self::assertEqualsWithDelta(0.2, $ac->share(), 1e-6);
        self::assertEqualsWithDelta(0.133333, $rapid->share(), 1e-6);
        self::assertSame('150.000', $breakdown->totalVolume);
        self::assertSame('0.155333', $breakdown->blendedPricePerUnit(), '23.30 over 150 kWh');
        self::assertNull(GradeStatistics::breakdown($history, EnergyKind::Liquid)->blendedPricePerUnit());
    }

    public function testNoGradesNoCard(): void
    {
        $history = FuelEconomy::analyse([$this->fill('1000', '40', '60'), $this->fill('1500', '35', '52.5')]);

        self::assertFalse(GradeStatistics::breakdown($history, EnergyKind::Liquid)->hasGrades());
    }

    public function testFormDefaultIsTheLastGradeOfThatFamilyThenTheVehicleDefault(): void
    {
        $hybrid = self::vehicle(FuelType::Phev, FuelGrade::E5_97);
        $entries = [
            $this->fill('1000', '40', '60', FuelGrade::E10_95),
            $this->fill('1100', '30', '9', FuelGrade::Home, Fuel::Electricity),
            $this->fill('1500', '35', '52.5'),
        ];

        // A plug-in hybrid, per family: the last graded petrol fill; for a charge, the last
        // charge's type, never the petrol grade.
        self::assertSame(FuelGrade::E10_95, GradeStatistics::formDefault($entries, $hybrid, Fuel::Petrol));
        self::assertSame(FuelGrade::Home, GradeStatistics::formDefault($entries, $hybrid, Fuel::Electricity));
        self::assertSame(FuelGrade::E5_97, GradeStatistics::formDefault([], $hybrid, Fuel::Petrol), 'the vehicle default');
        self::assertNull(GradeStatistics::formDefault([], $hybrid, Fuel::Electricity), 'the default is a petrol grade');
        self::assertNull(GradeStatistics::formDefault([], self::vehicle(FuelType::Petrol), Fuel::Petrol));
    }

    public function testRecentlyUsedGrades(): void
    {
        $entries = [
            $this->fill('500', '40', '60', FuelGrade::E0),     // more than 12 months before
            $this->fill('1000', '40', '60', FuelGrade::E5_97),
            $this->fill('1500', '40', '60', FuelGrade::E10_95),
            $this->fill('2000', '40', '60', FuelGrade::E10_95),
            $this->fill('2500', '40', '60'),
            $this->fill('3000', '40', '60', FuelGrade::E85),
        ];
        $since = $entries[1]->data->filledAt;

        self::assertSame(
            [FuelGrade::E10_95, FuelGrade::E85, FuelGrade::E5_97],
            GradeStatistics::recentlyUsed($entries, $since),
            'most used first, then most recent',
        );
    }

    /**
     * @param list<GradeSummary> $rows
     */
    private static function row(array $rows, FuelGrade $grade): GradeSummary
    {
        foreach ($rows as $row) {
            if ($row->grade === $grade) {
                return $row;
            }
        }
        self::fail('No row for ' . $grade->value);
    }

    private static function vehicle(FuelType $type, ?FuelGrade $default = null): Vehicle
    {
        $now = new DateTimeImmutable('2026-09-28');

        return new Vehicle(
            1,
            1,
            new VehicleData(VehicleType::Car, 'Make', 'Model', $type, defaultGrade: $default),
            VehicleStatus::Active,
            null,
            null,
            null,
            $now,
            $now,
        );
    }

    private function fill(
        string $km,
        string $volume,
        string $total,
        ?FuelGrade $grade = null,
        ?Fuel $fuel = null,
        bool $partial = false,
        bool $missedPrevious = false,
    ): FuelEntry {
        $id = $this->nextId++;
        $at = (new DateTimeImmutable('2025-01-01 08:00', new DateTimeZone('UTC')))->modify(sprintf('+%d days', $id * 30));

        return new FuelEntry(
            $id,
            1,
            new FuelEntryData(
                filledAt: $at,
                odometerKm: $km . '.000',
                fuel: $fuel ?? $grade?->family() ?? Fuel::Petrol,
                volume: $volume . '.000',
                pricePerUnit: '1.500000',
                totalCost: $total,
                isPartial: $partial,
                isMissedPrevious: $missedPrevious,
                grade: $grade,
            ),
            $at,
            $at,
        );
    }
}
