<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\MotHistory\MotDataSource;
use Logbook\Domain\MotHistory\MotDefect;
use Logbook\Domain\MotHistory\MotDefectRecord;
use Logbook\Domain\MotHistory\MotDefectType;
use Logbook\Domain\MotHistory\MotTest;
use Logbook\Domain\MotHistory\MotTestRecord;
use Logbook\Domain\MotHistory\MotTestResult;
use Logbook\Domain\MotHistory\MotVehicleState;
use Logbook\Domain\MotHistory\OdometerState;
use Logbook\Domain\MotHistory\RecallState;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use Logbook\Support\Units\DistanceUnit;

/**
 * Stored MOT tests and their defects (`mot_tests`, `mot_defects`), and a
 * vehicle's MOT history columns (spec.md §6, §7.38). Tests are upserted by
 * number, so a refresh never duplicates one; defects by their position.
 */
final readonly class MotTestRepository
{
    private const int KM_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<MotTest> newest first, each with its defects
     */
    public function listForVehicle(int $vehicleId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('mot_tests')
            ->where('vehicle_id = :vehicle')
            ->orderBy('completed_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->fetchAllAssociative();
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = Row::int($row, 'id');
        }
        $defects = $this->defectsFor($ids);
        $tests = [];
        foreach ($rows as $row) {
            $tests[] = $this->hydrate($row, $defects[Row::int($row, 'id')] ?? []);
        }

        return $tests;
    }

    public function find(int $vehicleId, int $testId): ?MotTest
    {
        foreach ($this->listForVehicle($vehicleId) as $test) {
            if ($test->id === $testId) {
                return $test;
            }
        }

        return null;
    }

    /**
     * Adds a test, or brings a stored one up to DVSA's answer (spec.md §7.38
     * *Upsert by test number*). Its defects are matched by position: text
     * and type are DVSA's, the issue and *Not now* are kept; ones DVSA no
     * longer lists go.
     *
     * @return array{0: int, 1: bool} the test's id, and whether it is new
     */
    public function upsert(int $vehicleId, MotTestRecord $record, DateTimeImmutable $now): array
    {
        $platform = $this->connection->getDatabasePlatform();
        $stamp = UtcDateTime::toDatabase($now, $platform);
        $columns = [
            'completed_at' => UtcDateTime::toDatabase($record->completedAt, $platform),
            'result' => $record->result->value,
            'expiry_on' => $record->expiryOn?->format('Y-m-d'),
            'odometer_km' => $record->odometerKm,
            'odometer_unit' => $record->odometerUnit?->value,
            'odometer_state' => $record->odometerState->value,
            'registration_at_test' => $record->registrationAtTest,
            'data_source' => $record->source->value,
            'fetched_at' => $stamp,
            'updated_at' => $stamp,
        ];
        $existing = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('mot_tests')
            ->where('vehicle_id = :vehicle')
            ->andWhere('test_number = :number')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('number', $record->number)
            ->fetchOne();
        $new = $existing === false;
        if ($new) {
            $this->connection->insert('mot_tests', [
                'vehicle_id' => $vehicleId,
                'test_number' => $record->number,
                'created_at' => $stamp,
            ] + $columns, ['vehicle_id' => ParameterType::INTEGER]);
            $id = (int) $this->connection->lastInsertId();
        } else {
            $id = self::ints([$existing])[0] ?? 0;
            $this->connection->update('mot_tests', $columns, ['id' => $id], ['id' => ParameterType::INTEGER]);
        }
        $this->upsertDefects($id, $record->defects, $stamp);

        return [$id, $new];
    }

    /**
     * @param list<MotDefectRecord> $defects
     */
    private function upsertDefects(int $testId, array $defects, string $stamp): void
    {
        $stored = $this->connection->createQueryBuilder()
            ->select('position')
            ->from('mot_defects')
            ->where('mot_test_id = :test')
            ->setParameter('test', $testId, ParameterType::INTEGER)
            ->fetchFirstColumn();
        $stored = self::ints($stored);
        foreach ($defects as $position => $defect) {
            $columns = [
                'type' => $defect->type->value,
                'text' => $defect->text,
                'dangerous' => $defect->dangerous,
                'updated_at' => $stamp,
            ];
            if (in_array($position, $stored, true)) {
                $this->connection->update(
                    'mot_defects',
                    $columns,
                    ['mot_test_id' => $testId, 'position' => $position],
                    ['dangerous' => ParameterType::BOOLEAN, 'mot_test_id' => ParameterType::INTEGER, 'position' => ParameterType::INTEGER],
                );
                continue;
            }
            $this->connection->insert('mot_defects', [
                'mot_test_id' => $testId,
                'position' => $position,
                'created_at' => $stamp,
            ] + $columns, [
                'mot_test_id' => ParameterType::INTEGER,
                'position' => ParameterType::INTEGER,
                'dangerous' => ParameterType::BOOLEAN,
            ]);
        }
        $this->connection->createQueryBuilder()
            ->delete('mot_defects')
            ->where('mot_test_id = :test')
            ->andWhere('position >= :count')
            ->setParameter('test', $testId, ParameterType::INTEGER)
            ->setParameter('count', count($defects), ParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        $ints = [];
        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ints[] = (int) $value;
            }
        }

        return $ints;
    }

    public function linkIssue(int $defectId, int $issueId): void
    {
        $this->connection->update(
            'mot_defects',
            ['issue_id' => $issueId],
            ['id' => $defectId],
            ['issue_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    public function dismiss(int $defectId, DateTimeImmutable $now): void
    {
        $this->connection->update(
            'mot_defects',
            ['dismissed_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())],
            ['id' => $defectId],
            ['id' => ParameterType::INTEGER],
        );
    }

    public function markReviewed(int $testId, DateTimeImmutable $now): void
    {
        $this->connection->update(
            'mot_tests',
            ['reviewed_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())],
            ['id' => $testId],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * *Stop and remove*: the tests, their defects and (by cascade) their
     * readings. Issues and documents made from them stay.
     */
    public function deleteForVehicle(int $vehicleId): void
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('mot_tests')
            ->where('vehicle_id = :vehicle')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->fetchFirstColumn();
        if ($ids === []) {
            return;
        }
        $ids = self::ints($ids);
        // Explicit, so no engine depends on cascades being switched on.
        foreach (['odometer_readings' => 'mot_test_id', 'mot_defects' => 'mot_test_id', 'mot_tests' => 'id'] as $table => $column) {
            $this->connection->createQueryBuilder()
                ->delete($table)
                ->where($column . ' IN (:ids)')
                ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
                ->executeStatement();
        }
    }

    public function state(int $vehicleId): MotVehicleState
    {
        $row = $this->connection->createQueryBuilder()
            ->select('mot_history_enabled_at', 'mot_history_fetched_at', 'mot_recall_state', 'mot_first_due_on')
            ->from('vehicles')
            ->where('id = :vehicle')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? new MotVehicleState() : $this->hydrateState($row);
    }

    /**
     * @param list<int> $vehicleIds
     * @return array<int, MotVehicleState> by vehicle id, for those with MOT history enabled
     */
    public function statesFor(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'mot_history_enabled_at', 'mot_history_fetched_at', 'mot_recall_state', 'mot_first_due_on')
            ->from('vehicles')
            ->where('id IN (:ids)')
            ->andWhere('mot_history_enabled_at IS NOT NULL')
            ->setParameter('ids', $vehicleIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();
        $states = [];
        foreach ($rows as $row) {
            $states[Row::int($row, 'id')] = $this->hydrateState($row);
        }

        return $states;
    }

    public function enable(int $vehicleId, DateTimeImmutable $at): void
    {
        $this->setVehicle($vehicleId, ['mot_history_enabled_at' => $this->instant($at)]);
    }

    public function saveFetch(int $vehicleId, DateTimeImmutable $at, RecallState $recall, ?DateTimeImmutable $firstDueOn): void
    {
        $this->setVehicle($vehicleId, [
            'mot_history_fetched_at' => $this->instant($at),
            'mot_recall_state' => $recall->value,
            'mot_first_due_on' => $firstDueOn?->format('Y-m-d'),
        ]);
    }

    public function clearVehicle(int $vehicleId): void
    {
        $this->setVehicle($vehicleId, [
            'mot_history_enabled_at' => null,
            'mot_history_fetched_at' => null,
            'mot_recall_state' => null,
            'mot_first_due_on' => null,
        ]);
    }

    /**
     * @param array<string, string|null> $columns
     */
    private function setVehicle(int $vehicleId, array $columns): void
    {
        $this->connection->update('vehicles', $columns, ['id' => $vehicleId], ['id' => ParameterType::INTEGER]);
    }

    private function instant(DateTimeImmutable $at): string
    {
        return UtcDateTime::toDatabase($at, $this->connection->getDatabasePlatform());
    }

    /**
     * @param list<int> $testIds
     * @return array<int, list<MotDefect>> by test id, in DVSA's order
     */
    private function defectsFor(array $testIds): array
    {
        if ($testIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('mot_defects')
            ->where('mot_test_id IN (:ids)')
            ->orderBy('mot_test_id')
            ->addOrderBy('position')
            ->setParameter('ids', $testIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();
        $platform = $this->connection->getDatabasePlatform();
        $byTest = [];
        foreach ($rows as $row) {
            $byTest[Row::int($row, 'mot_test_id')][] = new MotDefect(
                Row::int($row, 'id'),
                Row::int($row, 'mot_test_id'),
                Row::int($row, 'position'),
                MotDefectType::tryFrom(Row::string($row, 'type')) ?? MotDefectType::NonSpecific,
                Row::string($row, 'text'),
                Row::bool($row, 'dangerous'),
                Row::nullableInt($row, 'issue_id'),
                $row['dismissed_at'] === null ? null : UtcDateTime::fromDatabase($row['dismissed_at'], $platform),
            );
        }

        return $byTest;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<MotDefect> $defects
     */
    private function hydrate(array $row, array $defects): MotTest
    {
        $platform = $this->connection->getDatabasePlatform();

        return new MotTest(
            Row::int($row, 'id'),
            Row::int($row, 'vehicle_id'),
            Row::string($row, 'test_number'),
            UtcDateTime::fromDatabase($row['completed_at'] ?? null, $platform),
            MotTestResult::from(Row::string($row, 'result')),
            Row::nullableDate($row, 'expiry_on'),
            Row::nullableDecimal($row, 'odometer_km', self::KM_SCALE),
            DistanceUnit::tryFrom(Row::nullableString($row, 'odometer_unit') ?? ''),
            OdometerState::tryFrom(Row::string($row, 'odometer_state')) ?? OdometerState::None,
            Row::nullableString($row, 'registration_at_test'),
            MotDataSource::tryFrom(Row::string($row, 'data_source')) ?? MotDataSource::Dvsa,
            ($row['reviewed_at'] ?? null) === null ? null : UtcDateTime::fromDatabase($row['reviewed_at'], $platform),
            UtcDateTime::fromDatabase($row['fetched_at'] ?? null, $platform),
            $defects,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateState(array $row): MotVehicleState
    {
        $platform = $this->connection->getDatabasePlatform();
        $recall = Row::nullableString($row, 'mot_recall_state');

        return new MotVehicleState(
            ($row['mot_history_enabled_at'] ?? null) === null ? null : UtcDateTime::fromDatabase($row['mot_history_enabled_at'], $platform),
            ($row['mot_history_fetched_at'] ?? null) === null ? null : UtcDateTime::fromDatabase($row['mot_history_fetched_at'], $platform),
            $recall === null ? null : RecallState::tryFrom($recall),
            Row::nullableDate($row, 'mot_first_due_on'),
        );
    }
}
