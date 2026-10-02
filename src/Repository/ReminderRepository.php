<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Reminder\GeneratedReminder;
use Logbook\Service\Reminder\OpenReminderRow;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Reminders (`reminders`). Reads are scoped to the vehicle ids the access
 * policy gives (spec.md §5); writes take a reminder already resolved
 * through it.
 *
 * Notification bookkeeping goes through claim() / release() /
 * recordDelivery(): claim() is a conditional update, so of two overlapping
 * runs only one can win a reminder, and a re-run never sends it again.
 */
final readonly class ReminderRepository
{
    private const string TABLE = 'reminders';
    private const string DELIVERIES = 'reminder_deliveries';
    private const int KM_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<int> $vehicleIds the vehicles in scope (the access policy's visible ids)
     * @return list<Reminder>
     */
    public function listForVehicles(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $query = $this->select();
        self::scopeToVehicles($query, $vehicleIds);

        return $this->hydrateAll($query->orderBy('id')->fetchAllAssociative());
    }

    /**
     * Generated (schedule, document and tyre) reminders of these vehicles
     * (ReminderSync passes archived ones too, so it can remove theirs).
     *
     * @param list<int> $vehicleIds
     * @return list<Reminder>
     */
    public function listGeneratedForVehicles(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $generated = array_values(array_filter(
            ReminderSource::cases(),
            static fn (ReminderSource $source): bool => $source->isGenerated(),
        ));
        $query = $this->select()
            ->where('source IN (:sources)')
            ->setParameter(
                'sources',
                array_map(static fn (ReminderSource $source): string => $source->value, $generated),
                ArrayParameterType::STRING,
            );
        self::scopeToVehicles($query, $vehicleIds);

        return $this->hydrateAll($query->orderBy('id')->fetchAllAssociative());
    }

    /**
     * Open manual reminders of these vehicles.
     *
     * @param list<int> $vehicleIds
     * @return list<Reminder>
     */
    public function listOpenManualForVehicles(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $query = $this->select()
            ->where('source = :source', 'status IN (:open)')
            ->setParameter('source', ReminderSource::Manual->value)
            ->setParameter('open', self::openStatuses(), ArrayParameterType::STRING);
        self::scopeToVehicles($query, $vehicleIds);

        return $this->hydrateAll($query->orderBy('id')->fetchAllAssociative());
    }

    /**
     * Reminders of these vehicles whose current status has not been sent to
     * this user yet (spec.md §7.11: once per status and recipient).
     *
     * @param list<int> $vehicleIds
     * @return list<Reminder>
     */
    public function listAwaitingNotification(array $vehicleIds, int $userId): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $sent = $this->connection->createQueryBuilder()
            ->select('1')
            ->from(self::DELIVERIES, 'd')
            ->where('d.reminder_id = ' . self::TABLE . '.id', 'd.user_id = :user', 'd.status = ' . self::TABLE . '.status');
        $query = $this->select()
            ->where('status IN (:notifiable)')
            ->andWhere('NOT EXISTS (' . $sent->getSQL() . ')')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter(
                'notifiable',
                [ReminderStatus::Due->value, ReminderStatus::Overdue->value],
                ArrayParameterType::STRING,
            );
        self::scopeToVehicles($query, $vehicleIds);

        return $this->hydrateAll($query->orderBy('id')->fetchAllAssociative());
    }

    /**
     * Open reminders of these vehicles, only the columns the due counts
     * need (one query on the status index; spec.md §8).
     *
     * @param list<int> $vehicleIds
     * @return list<OpenReminderRow>
     */
    public function listOpenForCounts(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $query = $this->connection->createQueryBuilder()
            ->select('vehicle_id', 'source', 'status', 'due_on', 'lead_time_days')
            ->from(self::TABLE)
            ->where('status IN (:open)')
            ->setParameter('open', self::openStatuses(), ArrayParameterType::STRING);
        self::scopeToVehicles($query, $vehicleIds);

        $rows = [];
        foreach ($query->fetchAllAssociative() as $row) {
            $rows[] = new OpenReminderRow(
                vehicleId: Row::int($row, 'vehicle_id'),
                source: ReminderSource::tryFrom(Row::string($row, 'source')) ?? ReminderSource::Manual,
                status: ReminderStatus::tryFrom(Row::string($row, 'status')) ?? ReminderStatus::Upcoming,
                dueOn: Row::nullableDate($row, 'due_on'),
                leadTimeDays: Row::int($row, 'lead_time_days'),
            );
        }

        return $rows;
    }

    /**
     * Whichever vehicle it is for: the caller checks that vehicle with the
     * access policy.
     */
    public function findById(int $id): ?Reminder
    {
        $row = $this->select()->where('id = :id')->setParameter('id', $id, ParameterType::INTEGER)->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insertGenerated(GeneratedReminder $reminder, DateTimeImmutable $now): int
    {
        $timestamp = $this->timestamp($now);

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $reminder->vehicleId,
            'source' => $reminder->source->value,
            'source_id' => $reminder->sourceId,
            'status' => $reminder->status->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::generatedColumns($reminder), [
            'vehicle_id' => ParameterType::INTEGER,
            'source_id' => ParameterType::INTEGER,
            'lead_time_days' => ParameterType::INTEGER,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Bring a generated reminder in line with its source.
     *
     * @param bool $newOccurrence the source moved to a new due point: the
     *                            owner's dismissed/done and the notification
     *                            state belonged to the old one, so clear them
     */
    public function updateGenerated(
        int $id,
        GeneratedReminder $reminder,
        ReminderStatus $status,
        bool $newOccurrence,
        DateTimeImmutable $now,
    ): void {
        $columns = ['status' => $status->value, 'updated_at' => $this->timestamp($now)] + self::generatedColumns($reminder);
        if ($newOccurrence) {
            $columns += self::clearedNotification() + ['closed_at' => null];
            $this->clearDeliveries($id);
        }

        $this->connection->update(self::TABLE, $columns, ['id' => $id], [
            'id' => ParameterType::INTEGER,
            'lead_time_days' => ParameterType::INTEGER,
        ]);
    }

    public function insertManual(ManualReminderData $data, ReminderStatus $status, DateTimeImmutable $now): int
    {
        $timestamp = $this->timestamp($now);

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $data->vehicleId,
            'source' => ReminderSource::Manual->value,
            'status' => $status->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::manualColumns($data), [
            'vehicle_id' => ParameterType::INTEGER,
            'lead_time_days' => ParameterType::INTEGER,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param bool $newOccurrence the due date moved: reopen and forget what was sent
     */
    public function updateManual(
        int $id,
        ManualReminderData $data,
        ReminderStatus $status,
        bool $newOccurrence,
        DateTimeImmutable $now,
    ): void {
        $columns = [
            'vehicle_id' => $data->vehicleId,
            'status' => $status->value,
            'updated_at' => $this->timestamp($now),
        ] + self::manualColumns($data);
        if ($newOccurrence) {
            $columns += self::clearedNotification() + ['closed_at' => null];
            $this->clearDeliveries($id);
        }

        $this->connection->update(self::TABLE, $columns, ['id' => $id], [
            'id' => ParameterType::INTEGER,
            'vehicle_id' => ParameterType::INTEGER,
            'lead_time_days' => ParameterType::INTEGER,
        ]);
    }

    /**
     * Set the status; closing (dismiss / done) stamps closed_at, reopening clears it.
     */
    public function setStatus(int $id, ReminderStatus $status, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'status' => $status->value,
            'closed_at' => $status->isClosed() ? $this->timestamp($now) : null,
            'updated_at' => $this->timestamp($now),
        ], ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    /**
     * Mark a source's open reminder done (spec.md §7.32: ending an agreement
     * marks its finance reminders done). Closed ones stay as they are.
     */
    public function markDone(int $vehicleId, ReminderSource $source, int $sourceId, DateTimeImmutable $now): void
    {
        $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('status', ':done')
            ->set('closed_at', ':now')
            ->set('updated_at', ':now')
            ->where('vehicle_id = :vehicle', 'source = :source', 'source_id = :source_id', 'status IN (:open)')
            ->setParameter('done', ReminderStatus::Done->value)
            ->setParameter('now', $this->timestamp($now))
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('source', $source->value)
            ->setParameter('source_id', $sourceId, ParameterType::INTEGER)
            ->setParameter(
                'open',
                [ReminderStatus::Upcoming->value, ReminderStatus::Due->value, ReminderStatus::Overdue->value],
                ArrayParameterType::STRING,
            )
            ->executeStatement();
    }

    public function delete(int $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    /**
     * Take a reminder for sending to one user in its current status, by
     * writing its delivery row: the unique (reminder, user, status) lets one
     * caller win. Never inside a transaction (a failed insert would abort a
     * PostgreSQL one).
     */
    public function claim(Reminder $reminder, int $userId, DateTimeImmutable $now): bool
    {
        try {
            $this->connection->insert(self::DELIVERIES, [
                'reminder_id' => $reminder->id,
                'user_id' => $userId,
                'status' => $reminder->status->value,
                'created_at' => $this->timestamp($now),
            ], ['reminder_id' => ParameterType::INTEGER, 'user_id' => ParameterType::INTEGER]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Undo claim() after nothing could be delivered, so the next run retries.
     */
    public function release(Reminder $reminder, int $userId): void
    {
        $this->connection->createQueryBuilder()
            ->delete(self::DELIVERIES)
            ->where('reminder_id = :reminder', 'user_id = :user', 'status = :status', 'sent_at IS NULL')
            ->setParameter('reminder', $reminder->id, ParameterType::INTEGER)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('status', $reminder->status->value)
            ->executeStatement();
    }

    /**
     * Record what reached one user for a claimed reminder, and on the
     * reminder itself the latest delivery to anyone.
     *
     * @param list<string> $channels
     */
    public function recordDelivery(Reminder $reminder, int $userId, array $channels, DateTimeImmutable $now): void
    {
        sort($channels);
        $this->connection->createQueryBuilder()
            ->update(self::DELIVERIES)
            ->set('channels', ':channels')
            ->set('sent_at', ':now')
            ->where('reminder_id = :reminder', 'user_id = :user', 'status = :status')
            ->setParameter('channels', $this->json($channels))
            ->setParameter('now', $this->timestamp($now))
            ->setParameter('reminder', $reminder->id, ParameterType::INTEGER)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('status', $reminder->status->value)
            ->executeStatement();

        $all = array_values(array_unique([...$reminder->channelsNotified, ...$channels]));
        sort($all);
        $this->connection->update(self::TABLE, [
            'notified_status' => $reminder->status->value,
            'last_notified_at' => $this->timestamp($now),
            'channels_notified' => $this->json($all),
        ], ['id' => $reminder->id], ['id' => ParameterType::INTEGER]);
    }

    /**
     * Who has been sent this reminder, in which status (for tests and the
     * restore of old backups).
     *
     * @return list<array{user_id: int, status: string, sent: bool}>
     */
    public function deliveriesOf(int $reminderId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('user_id', 'status', 'sent_at')
            ->from(self::DELIVERIES)
            ->where('reminder_id = :reminder')
            ->setParameter('reminder', $reminderId, ParameterType::INTEGER)
            ->orderBy('user_id')
            ->addOrderBy('status')
            ->fetchAllAssociative();

        return array_values(array_map(static fn (array $row): array => [
            'user_id' => Row::int($row, 'user_id'),
            'status' => Row::string($row, 'status'),
            'sent' => ($row['sent_at'] ?? null) !== null,
        ], $rows));
    }

    /**
     * A new occurrence starts afresh for every recipient.
     */
    private function clearDeliveries(int $reminderId): void
    {
        $this->connection->delete(self::DELIVERIES, ['reminder_id' => $reminderId], ['reminder_id' => ParameterType::INTEGER]);
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'vehicle_id', 'source', 'source_id', 'occurrence', 'category', 'title', 'notes')
            ->addSelect('due_on', 'due_km', 'lead_time_days', 'status', 'notified_status', 'channels_notified')
            ->addSelect('last_notified_at', 'closed_at', 'created_at', 'updated_at')
            ->from(self::TABLE);
    }

    /**
     * @param non-empty-list<int> $vehicleIds
     */
    private static function scopeToVehicles(QueryBuilder $query, array $vehicleIds): void
    {
        $query->andWhere('vehicle_id IN (:vehicles)')->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER);
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function generatedColumns(GeneratedReminder $reminder): array
    {
        return [
            'occurrence' => $reminder->occurrence,
            'category' => $reminder->category,
            'title' => $reminder->title,
            'due_on' => $reminder->dueOn?->format('Y-m-d'),
            'due_km' => $reminder->dueKm,
            'lead_time_days' => $reminder->leadTimeDays,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function manualColumns(ManualReminderData $data): array
    {
        return [
            'title' => $data->title,
            'notes' => $data->notes,
            'due_on' => $data->dueOn?->format('Y-m-d'),
            'due_km' => $data->dueKm,
            'lead_time_days' => $data->leadTimeDays,
        ];
    }

    /**
     * @return array<string, null>
     */
    private static function clearedNotification(): array
    {
        return ['notified_status' => null, 'channels_notified' => null, 'last_notified_at' => null];
    }

    /**
     * @return list<string>
     */
    private static function openStatuses(): array
    {
        return [ReminderStatus::Upcoming->value, ReminderStatus::Due->value, ReminderStatus::Overdue->value];
    }

    private function timestamp(DateTimeImmutable $instant): string
    {
        return UtcDateTime::toDatabase($instant, $this->connection->getDatabasePlatform());
    }

    /**
     * @param list<string> $value
     */
    private function json(array $value): mixed
    {
        return Type::getType(Types::JSON)->convertToDatabaseValue($value, $this->connection->getDatabasePlatform());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<Reminder>
     */
    private function hydrateAll(array $rows): array
    {
        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Reminder
    {
        $platform = $this->connection->getDatabasePlatform();
        $channels = Type::getType(Types::JSON)->convertToPHPValue($row['channels_notified'] ?? null, $platform);
        $notified = Row::nullableString($row, 'notified_status');
        $lastNotified = $row['last_notified_at'] ?? null;
        $closed = $row['closed_at'] ?? null;

        return new Reminder(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            source: ReminderSource::tryFrom(Row::string($row, 'source')) ?? ReminderSource::Manual,
            sourceId: Row::nullableInt($row, 'source_id'),
            occurrence: Row::nullableString($row, 'occurrence'),
            category: Row::nullableString($row, 'category'),
            title: Row::nullableString($row, 'title') ?? '',
            notes: Row::nullableString($row, 'notes'),
            dueOn: Row::nullableDate($row, 'due_on'),
            dueKm: Row::nullableDecimal($row, 'due_km', self::KM_SCALE),
            leadTimeDays: Row::int($row, 'lead_time_days'),
            status: ReminderStatus::tryFrom(Row::string($row, 'status')) ?? ReminderStatus::Upcoming,
            notifiedStatus: $notified === null ? null : ReminderStatus::tryFrom($notified),
            channelsNotified: is_array($channels) ? array_values(array_filter($channels, is_string(...))) : [],
            lastNotifiedAt: $lastNotified === null ? null : UtcDateTime::fromDatabase($lastNotified, $platform),
            closedAt: $closed === null ? null : UtcDateTime::fromDatabase($closed, $platform),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
