<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueSource;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Issue\IssueUpdate;
use Logbook\Domain\Issue\IssueUpdateReason;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use UnexpectedValueException;

/**
 * Issues (`issues`), their fixes (`issue_fixes`) and their timelines
 * (`issue_updates`), spec.md §6 Issue, IssueFix and IssueUpdate. Vehicle
 * queries are scoped to a vehicle the caller has already resolved.
 */
final readonly class IssueRepository
{
    private const string TABLE = 'issues';
    private const string FIXES = 'issue_fixes';
    private const string UPDATES = 'issue_updates';
    public const int KM_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<IssueStatus> $statuses none = every status
     * @return list<Issue> safety first, then newest noticed first
     */
    public function listForVehicle(int $vehicleId, array $statuses = []): array
    {
        return $this->listForVehicles([$vehicleId], $statuses);
    }

    /**
     * @param list<int> $vehicleIds
     * @param list<IssueStatus> $statuses none = every status
     * @return list<Issue> safety first, then newest noticed first
     */
    public function listForVehicles(array $vehicleIds, array $statuses = []): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $query = $this->select()
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER);
        if ($statuses !== []) {
            $query->andWhere('status IN (:statuses)')->setParameter(
                'statuses',
                array_map(static fn (IssueStatus $s): string => $s->value, $statuses),
                ArrayParameterType::STRING,
            );
        }
        $issues = array_values(array_map($this->hydrate(...), $query->fetchAllAssociative()));
        usort($issues, self::order(...));

        return $issues;
    }

    /**
     * Issues of several vehicles noticed or fixed in a calendar date range
     * (History).
     *
     * @param list<int> $vehicleIds
     * @param DateTimeImmutable|null $from inclusive; null = from the start
     * @param DateTimeImmutable|null $until inclusive; null = to the end
     * @return list<Issue>
     */
    public function listTouching(array $vehicleIds, ?DateTimeImmutable $from, ?DateTimeImmutable $until): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $query = $this->select()
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER);
        $noticed = [];
        $fixed = ['fixed_on IS NOT NULL'];
        if ($from !== null) {
            $noticed[] = 'noticed_on >= :from';
            $fixed[] = 'fixed_on >= :from';
            $query->setParameter('from', $from->format('Y-m-d'));
        }
        if ($until !== null) {
            $noticed[] = 'noticed_on <= :until';
            $fixed[] = 'fixed_on <= :until';
            $query->setParameter('until', $until->format('Y-m-d'));
        }
        if ($noticed !== []) {
            $query->andWhere(sprintf('(%s) OR (%s)', implode(' AND ', $noticed), implode(' AND ', $fixed)));
        }

        return array_values(array_map($this->hydrate(...), $query->fetchAllAssociative()));
    }

    public function find(int $vehicleId, int $id): ?Issue
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(
        int $vehicleId,
        IssueData $data,
        DateTimeImmutable $now,
        ?int $createdBy,
        IssueSource $source = IssueSource::Manual,
        ?string $sourceRef = null,
    ): int {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_by' => $createdBy,
            'source' => $source->value,
            'source_ref' => $sourceRef,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), self::types() + [
            'vehicle_id' => ParameterType::INTEGER,
            'created_by' => ParameterType::INTEGER,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, IssueData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            self::types() + ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Set the fixed state: the date and the status to return to, or both
     * null when the issue is no longer fixed.
     */
    public function setFixed(
        int $vehicleId,
        int $id,
        ?DateTimeImmutable $fixedOn,
        ?IssueStatus $before,
        DateTimeImmutable $now,
    ): void {
        $this->connection->update(
            self::TABLE,
            [
                'fixed_on' => $fixedOn?->format('Y-m-d'),
                'status_before_fix' => $before?->value,
                'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
            ],
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Deleting removes its fixes, updates and readings (`CASCADE`).
     */
    public function delete(int $vehicleId, int $id): void
    {
        $this->connection->delete(
            self::TABLE,
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    // Fixes

    /**
     * Link a record as a current fix; a link kept as history becomes current again.
     */
    public function addFix(int $issueId, int $recordId, DateTimeImmutable $now): void
    {
        if (in_array($recordId, $this->fixesOf($issueId), true)) {
            $this->connection->update(
                self::FIXES,
                ['historical' => false],
                ['issue_id' => $issueId, 'maintenance_entry_id' => $recordId],
                [
                    'historical' => ParameterType::BOOLEAN,
                    'issue_id' => ParameterType::INTEGER,
                    'maintenance_entry_id' => ParameterType::INTEGER,
                ],
            );

            return;
        }
        $this->connection->insert(self::FIXES, [
            'issue_id' => $issueId,
            'maintenance_entry_id' => $recordId,
            'created_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['issue_id' => ParameterType::INTEGER, 'maintenance_entry_id' => ParameterType::INTEGER]);
    }

    public function removeFix(int $issueId, int $recordId): void
    {
        $this->connection->delete(
            self::FIXES,
            ['issue_id' => $issueId, 'maintenance_entry_id' => $recordId],
            ['issue_id' => ParameterType::INTEGER, 'maintenance_entry_id' => ParameterType::INTEGER],
        );
    }

    /**
     * *It's back*: the issue's fixes so far are kept as history.
     */
    public function markHistorical(int $issueId): void
    {
        $this->connection->update(
            self::FIXES,
            ['historical' => true],
            ['issue_id' => $issueId],
            ['historical' => ParameterType::BOOLEAN, 'issue_id' => ParameterType::INTEGER],
        );
    }

    /**
     * @param bool $currentOnly leave out the fixes kept as history (*It's back*)
     * @return list<int> the service record ids that fixed the issue, oldest link first
     */
    public function fixesOf(int $issueId, bool $currentOnly = false): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('maintenance_entry_id')
            ->from(self::FIXES)
            ->where('issue_id = :issue');
        if ($currentOnly) {
            $query->andWhere('historical = :no')->setParameter('no', false, ParameterType::BOOLEAN);
        }
        $ids = $query
            ->orderBy('created_at')
            ->addOrderBy('maintenance_entry_id')
            ->setParameter('issue', $issueId, ParameterType::INTEGER)
            ->fetchFirstColumn();

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
    }

    /**
     * @param bool $currentOnly leave out the links kept as history (*It's back*)
     * @return list<int> the issue ids a service record fixes
     */
    public function fixedBy(int $recordId, bool $currentOnly = false): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('issue_id')
            ->from(self::FIXES)
            ->where('maintenance_entry_id = :record');
        if ($currentOnly) {
            $query->andWhere('historical = :no')->setParameter('no', false, ParameterType::BOOLEAN);
        }
        $ids = $query
            ->orderBy('issue_id')
            ->setParameter('record', $recordId, ParameterType::INTEGER)
            ->fetchFirstColumn();

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
    }

    /**
     * Every fix link of a vehicle's issues, in one query.
     *
     * @param list<int> $issueIds
     * @return array<int, list<int>> record ids by issue id
     */
    public function fixesFor(array $issueIds): array
    {
        if ($issueIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('issue_id', 'maintenance_entry_id')
            ->from(self::FIXES)
            ->where('issue_id IN (:issues)')
            ->orderBy('created_at')
            ->addOrderBy('maintenance_entry_id')
            ->setParameter('issues', $issueIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();
        $fixes = [];
        foreach ($rows as $row) {
            $fixes[Row::int($row, 'issue_id')][] = Row::int($row, 'maintenance_entry_id');
        }

        return $fixes;
    }

    /**
     * When each watching issue was last set to watching: its latest
     * automatic line to `watching` (*Look again*'s "watching since").
     *
     * @param list<int> $issueIds
     * @return array<int, DateTimeImmutable> by issue id
     */
    public function watchingSince(array $issueIds): array
    {
        if ($issueIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('issue_id', 'MAX(noted_on) AS since')
            ->from(self::UPDATES)
            ->where('issue_id IN (:issues)', 'status_to = :watching')
            ->groupBy('issue_id')
            ->setParameter('issues', $issueIds, ArrayParameterType::INTEGER)
            ->setParameter('watching', IssueStatus::Watching->value)
            ->fetchAllAssociative();
        $since = [];
        foreach ($rows as $row) {
            $date = Row::nullableDate($row, 'since');
            if ($date !== null) {
                $since[Row::int($row, 'issue_id')] = $date;
            }
        }

        return $since;
    }

    /**
     * What fixed each issue, for History's second line: the records' titles
     * and dates, oldest link first, in one query.
     *
     * @param list<int> $issueIds
     * @return array<int, list<array{title: string, date: DateTimeImmutable}>> by issue id
     */
    public function fixSummaries(array $issueIds): array
    {
        if ($issueIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('f.issue_id', 'm.title', 'm.performed_on')
            ->from(self::FIXES, 'f')
            ->innerJoin('f', 'maintenance_entries', 'm', 'm.id = f.maintenance_entry_id')
            ->where('f.issue_id IN (:issues)', 'f.historical = :no')
            ->setParameter('no', false, ParameterType::BOOLEAN)
            ->orderBy('f.created_at')
            ->addOrderBy('f.id')
            ->setParameter('issues', $issueIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();
        $fixes = [];
        foreach ($rows as $row) {
            $date = Row::nullableDate($row, 'performed_on');
            if ($date !== null) {
                $fixes[Row::int($row, 'issue_id')][] = ['title' => Row::string($row, 'title'), 'date' => $date];
            }
        }

        return $fixes;
    }

    // Updates

    public function insertUpdate(
        int $issueId,
        DateTimeImmutable $notedOn,
        ?string $odometerKm,
        ?string $note,
        ?IssueStatus $from,
        ?IssueStatus $to,
        ?IssueUpdateReason $reason,
        ?int $createdBy,
        DateTimeImmutable $now,
    ): int {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(self::UPDATES, [
            'issue_id' => $issueId,
            'noted_on' => $notedOn->format('Y-m-d'),
            'odometer_km' => $odometerKm,
            'note' => $note,
            'status_from' => $from?->value,
            'status_to' => $to?->value,
            'reason' => $reason?->value,
            'created_by' => $createdBy,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], ['issue_id' => ParameterType::INTEGER, 'created_by' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateNote(
        int $issueId,
        int $id,
        DateTimeImmutable $notedOn,
        ?string $odometerKm,
        ?string $note,
        DateTimeImmutable $now,
    ): void {
        $this->connection->update(self::UPDATES, [
            'noted_on' => $notedOn->format('Y-m-d'),
            'odometer_km' => $odometerKm,
            'note' => $note,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['issue_id' => $issueId, 'id' => $id], ['issue_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER]);
    }

    public function deleteUpdate(int $issueId, int $id): void
    {
        $this->connection->delete(
            self::UPDATES,
            ['issue_id' => $issueId, 'id' => $id],
            ['issue_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    public function findUpdate(int $issueId, int $id): ?IssueUpdate
    {
        $row = $this->selectUpdates()
            ->where('issue_id = :issue', 'id = :id')
            ->setParameter('issue', $issueId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateUpdate($row);
    }

    /**
     * The issue id an update belongs to, if that issue is on the vehicle.
     */
    public function issueOfUpdate(int $vehicleId, int $updateId): ?int
    {
        $id = $this->connection->createQueryBuilder()
            ->select('u.issue_id')
            ->from(self::UPDATES, 'u')
            ->innerJoin('u', self::TABLE, 'i', 'i.id = u.issue_id')
            ->where('u.id = :update', 'i.vehicle_id = :vehicle')
            ->setParameter('update', $updateId, ParameterType::INTEGER)
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->fetchOne();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return list<IssueUpdate> oldest first: by date, then the order added
     */
    public function updatesOf(int $issueId): array
    {
        $rows = $this->selectUpdates()
            ->where('issue_id = :issue')
            ->orderBy('noted_on')
            ->addOrderBy('id')
            ->setParameter('issue', $issueId, ParameterType::INTEGER)
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrateUpdate(...), $rows));
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'vehicle_id',
                'created_by',
                'noticed_on',
                'odometer_km',
                'title',
                'description',
                'category',
                'status',
                'affects_safety',
                'look_again_on',
                'look_again_km',
                'fixed_on',
                'status_before_fix',
                'source',
                'source_ref',
                'created_at',
                'updated_at',
            )
            ->from(self::TABLE);
    }

    private function selectUpdates(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'issue_id', 'noted_on', 'odometer_km', 'note', 'status_from', 'status_to', 'reason')
            ->addSelect('created_by', 'created_at')
            ->from(self::UPDATES);
    }

    /**
     * Safety issues first, then the newest noticed, then the latest logged.
     */
    private static function order(Issue $a, Issue $b): int
    {
        return [$b->data->affectsSafety, $b->data->noticedOn, $b->id] <=> [$a->data->affectsSafety, $a->data->noticedOn, $a->id];
    }

    /**
     * @return array<string, bool|string|null>
     */
    private static function dataColumns(IssueData $data): array
    {
        $watching = $data->status === IssueStatus::Watching;

        return [
            'noticed_on' => $data->noticedOn->format('Y-m-d'),
            'odometer_km' => $data->odometerKm,
            'title' => $data->title,
            'description' => $data->description,
            'category' => $data->category?->value,
            'status' => $data->status->value,
            'affects_safety' => $data->affectsSafety,
            'look_again_on' => $watching ? $data->lookAgainOn?->format('Y-m-d') : null,
            'look_again_km' => $watching ? $data->lookAgainKm : null,
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private static function types(): array
    {
        return ['affects_safety' => ParameterType::BOOLEAN];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Issue
    {
        $platform = $this->connection->getDatabasePlatform();
        $category = Row::nullableString($row, 'category');
        $before = Row::nullableString($row, 'status_before_fix');

        return new Issue(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new IssueData(
                noticedOn: Row::nullableDate($row, 'noticed_on')
                    ?? throw new UnexpectedValueException('Column "noticed_on" is null.'),
                title: Row::string($row, 'title'),
                status: IssueStatus::from(Row::string($row, 'status')),
                odometerKm: Row::nullableDecimal($row, 'odometer_km', self::KM_SCALE),
                description: Row::nullableString($row, 'description'),
                category: $category === null ? null : MaintenanceCategory::tryFrom($category),
                affectsSafety: Row::bool($row, 'affects_safety'),
                lookAgainOn: Row::nullableDate($row, 'look_again_on'),
                lookAgainKm: Row::nullableDecimal($row, 'look_again_km', self::KM_SCALE),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            createdBy: Row::nullableInt($row, 'created_by'),
            fixedOn: Row::nullableDate($row, 'fixed_on'),
            statusBeforeFix: $before === null ? null : IssueStatus::tryFrom($before),
            source: IssueSource::tryFrom(Row::string($row, 'source')) ?? IssueSource::Manual,
            sourceRef: Row::nullableString($row, 'source_ref'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateUpdate(array $row): IssueUpdate
    {
        $from = Row::nullableString($row, 'status_from');
        $to = Row::nullableString($row, 'status_to');
        $reason = Row::nullableString($row, 'reason');

        return new IssueUpdate(
            id: Row::int($row, 'id'),
            issueId: Row::int($row, 'issue_id'),
            notedOn: Row::nullableDate($row, 'noted_on')
                ?? throw new UnexpectedValueException('Column "noted_on" is null.'),
            odometerKm: Row::nullableDecimal($row, 'odometer_km', self::KM_SCALE),
            note: Row::nullableString($row, 'note'),
            statusFrom: $from === null ? null : IssueStatus::tryFrom($from),
            statusTo: $to === null ? null : IssueStatus::tryFrom($to),
            reason: $reason === null ? null : IssueUpdateReason::tryFrom($reason),
            createdBy: Row::nullableInt($row, 'created_by'),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $this->connection->getDatabasePlatform()),
        );
    }
}
