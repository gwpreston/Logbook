<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\MotHistory;

use Logbook\Domain\MotHistory\MotDataSource;
use Logbook\Domain\MotHistory\MotDefectType;
use Logbook\Domain\MotHistory\MotTestResult;
use Logbook\Domain\MotHistory\MotVehicleRecord;
use Logbook\Domain\MotHistory\OdometerState;
use Logbook\Domain\MotHistory\RecallState;
use Logbook\Service\MotHistory\Uk\DvsaParser;
use Logbook\Support\Units\DistanceUnit;
use PHPUnit\Framework\TestCase;

/**
 * DVSA's answers read field by field (spec.md §4, §7.38; #333, #334), from
 * the synthetic fixtures in tests/Fixtures/mot-history.
 */
final class DvsaParserTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../../Fixtures/mot-history/';

    public function testVehicleAndTestsNewestFirst(): void
    {
        $vehicle = $this->fixture('vehicle-with-tests.json');

        self::assertSame('AB12CDE', $vehicle->registration);
        self::assertSame('VOLKSWAGEN', $vehicle->make);
        self::assertSame('GOLF MATCH TSI', $vehicle->model);
        self::assertSame('Petrol', $vehicle->fuelType);
        self::assertSame('Silver', $vehicle->colour);
        self::assertSame('2012-03-14', $vehicle->registeredOn?->format('Y-m-d'));
        self::assertSame(RecallState::Yes, $vehicle->recall);
        self::assertNull($vehicle->firstDueOn);
        self::assertSame(
            ['323456789012', '223456789012', '123456789012', 'dva_ni:2019-03-01T08:00:00Z', '423456789012'],
            array_map(static fn ($test): string => $test->number, $vehicle->tests),
        );
        // The heavy-vehicle test has no completed date: skipped and counted (#334).
        self::assertSame(1, $vehicle->undated);
    }

    public function testMilesAreConvertedAndTheTestedUnitKept(): void
    {
        $test = $this->fixture('vehicle-with-tests.json')->tests[1];

        self::assertSame(MotTestResult::Failed, $test->result);
        self::assertSame('2026-02-14 09:17:46', $test->completedAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $test->completedAt->getTimezone()->getName());
        self::assertNull($test->expiryOn);
        self::assertSame(OdometerState::Read, $test->odometerState);
        self::assertSame(DistanceUnit::Mile, $test->odometerUnit);
        self::assertSame('70730.669', $test->odometerKm);
        self::assertSame(MotDataSource::Dvsa, $test->source);
    }

    public function testKilometresAndANumberlessNorthernIrelandTest(): void
    {
        $test = $this->fixture('vehicle-with-tests.json')->tests[3];

        self::assertSame(MotDataSource::DvaNi, $test->source);
        self::assertSame('dva_ni:2019-03-01T08:00:00Z', $test->number);
        self::assertSame('50123.000', $test->odometerKm);
        self::assertSame(DistanceUnit::Kilometre, $test->odometerUnit);
        self::assertNull($test->registrationAtTest);
        self::assertSame([], $test->defects);
    }

    public function testAnUnreadableOdometerHasNoReading(): void
    {
        $test = $this->fixture('vehicle-with-tests.json')->tests[4];

        self::assertSame(OdometerState::Unreadable, $test->odometerState);
        self::assertNull($test->odometerKm);
        self::assertNull($test->odometerUnit);
    }

    public function testEveryDefectTypeAndTheUnknownOnes(): void
    {
        $defects = $this->fixture('vehicle-with-tests.json')->tests[1]->defects;

        self::assertSame([
            MotDefectType::Dangerous,
            MotDefectType::Major,
            MotDefectType::Fail,
            MotDefectType::UserEntered,
            MotDefectType::SystemGenerated,
            MotDefectType::NonSpecific,
            // A null type and one DVSA adds later are both `non_specific` (#333).
            MotDefectType::NonSpecific,
            MotDefectType::NonSpecific,
        ], array_map(static fn ($defect): MotDefectType => $defect->type, $defects));
        self::assertTrue($defects[0]->dangerous);
        self::assertFalse($defects[1]->dangerous);
        self::assertFalse($defects[5]->dangerous, 'a null flag is not dangerous');
        self::assertCount(8, $defects, 'a defect with no text is dropped');
    }

    public function testDefectTextIsTidied(): void
    {
        $defects = $this->fixture('vehicle-with-tests.json')->tests[2]->defects;

        self::assertSame('Offside Rear Brake pipe slightly corroded (1.1.11 (c))', $defects[1]->text);
        self::assertSame(MotDefectType::Minor, $defects[2]->type);
    }

    public function testANewVehicleHasItsFirstDueDateAndNoTests(): void
    {
        $vehicle = $this->fixture('new-vehicle.json');

        self::assertSame([], $vehicle->tests);
        self::assertSame('2028-03-13', $vehicle->firstDueOn?->format('Y-m-d'));
        self::assertSame(RecallState::Unknown, $vehicle->recall);
        self::assertSame('FORD', $vehicle->make);
    }

    public function testAnAnswerThatIsNotAVehicle(): void
    {
        self::assertNull(DvsaParser::vehicle(['errorCode' => 'MOTH-NF-01']));
        self::assertNull(DvsaParser::vehicle([['hasOutstandingRecall' => 'No']]));
    }

    public function testOddValuesAreRefusedFieldByField(): void
    {
        $vehicle = DvsaParser::vehicle([
            'hasOutstandingRecall' => 'Maybe',
            'make' => '   ',
            'registrationDate' => '2026-02-30',
            'motTests' => [
                'not a test',
                ['completedDate' => '2024-05-06', 'testResult' => 'PASSED', 'dataSource' => 'DVSA',
                    'odometerResultType' => 'READ', 'odometerValue' => '12,000', 'odometerUnit' => 'MI',
                    'motTestNumber' => '12 34'],
                ['completedDate' => '2024-05-07T10:00:00+01:00', 'testResult' => 'ABANDONED'],
                ['completedDate' => 'yesterday', 'testResult' => 'PASSED'],
            ],
        ]);

        self::assertNotNull($vehicle);
        self::assertSame(RecallState::Unavailable, $vehicle->recall);
        self::assertNull($vehicle->make);
        self::assertNull($vehicle->registeredOn);
        self::assertCount(1, $vehicle->tests);
        self::assertSame(2, $vehicle->undated);
        $test = $vehicle->tests[0];
        // A date with no time is dated at noon UTC, so it stays on its day.
        self::assertSame('2024-05-06 12:00:00', $test->completedAt->format('Y-m-d H:i:s'));
        // "Read" but no usable figure: nothing to log.
        self::assertSame(OdometerState::None, $test->odometerState);
        self::assertNull($test->odometerKm);
        // A number with a space is not trusted as a key.
        self::assertSame('dvsa:2024-05-06T12:00:00Z', $test->number);
    }

    public function testAnOffsetTimeIsStoredInUtc(): void
    {
        $vehicle = DvsaParser::vehicle([
            'hasOutstandingRecall' => 'No',
            'motTests' => [['completedDate' => '2024-05-07T10:00:00.250+01:00', 'testResult' => 'PASSED',
                'motTestNumber' => '1', 'dataSource' => 'DVSA', 'odometerResultType' => 'NO_ODOMETER']],
        ]);

        self::assertNotNull($vehicle);
        // Whole seconds, so a refresh compares equal to what is stored.
        self::assertSame('2024-05-07 09:00:00.000', $vehicle->tests[0]->completedAt->format('Y-m-d H:i:s.v'));
        self::assertSame(RecallState::No, $vehicle->recall);
    }

    private function fixture(string $name): MotVehicleRecord
    {
        $data = json_decode((string) file_get_contents(self::DIR . $name), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $vehicle = DvsaParser::vehicle($data);
        self::assertNotNull($vehicle);

        return $vehicle;
    }
}
