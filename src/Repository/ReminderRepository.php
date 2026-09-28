<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Service\Reminder\GeneratedReminder;
use Logbook\Service\Reminder\OpenReminderRow;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Reminders (`reminders`). Reads are scoped to an owner through their
 * vehicles; writes take a reminder already resolved for that owner.
 *
 * Notification bookkeeping goes through claim() / release() /
 * recordDelivery(): claim() is a conditional update, so of two overlapping
 * runs only one can win a reminder, and a re-run never sends it again.
 */
final readonly class ReminderRepository
{
    private const string TABLE = 'reminders';
    private const int KM_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param bool $activeVehiclesOnly leave out the reminders of archived vehicles
     * @return list<Reminder>
     */
    public function listForUser(int $userId, bool $activeVehiclesOnly = true): array
    {
        $query = $this->select();
        $this->scopeToUser($query, $userId, $activeVehiclesOnly);

        return $this->hydrateAll($query->orderBy('id')->fetchAllAssociative());
    }

    /**
     * Schedule and document reminders of every vehicle of the owner (archived
     * ones included, so ReminderSync can remove theirs).
     *
     * @return list<Reminder>
     */
    public function listGeneratedForUser(int $userId): array
    {
        $query = $this->select()
            ->where('source IN (:sources)')
            ->setParameter(
                'sources',
                [ReminderSource::Schedule->value, ReminderSource::Compliance->value],
                ArrayParameterType::STRING,
            );
        $this->scopeToUser($query, $userId, false);

        return $this->hydrateAll($query->orderBy('id')->fetchAllAssociative());
    }

    /**
     * Open manual reminders of the owner's active vehicles.
     *
     * @return list<Reminder>
     */
    public function listOpenManualForUser(int $userId): array
    {
        $query = $this->select()
            ->where('source = :source', 'status IN (:open)')
            ->setParameter('source', ReminderSource::Manual->value)
            ->setParameter('open', self::openStatuses(), ArrayParameterType::STRING);
        $this->scopeToUser($query, $userId, true);

        return $this->hydrateAll($query->orderBy('id')->fetchAllAssociative());
    }

    /**
     * Reminders of the owner's active vehicles whose current status has not
     * been sent out yet.
     *
     * @return list<Reminder>
     */
    public function listAwaitingNotification(int $userId): array
    {
        $query = $this->select()
            ->where('status IN (:notifiable)')
            ->andWhere('notified_status IS NULL OR notified_status <> status')
            ->setParameter(
                'notifiable',
                [ReminderStatus::Due->value, ReminderStatus::Overdue->value],
                ArrayParameterType::STRING,
            );
        $this->scopeToUser($query, $userId, true);

        return $this->hydrateAll($query->orderBy('id')->fetchAllAssociative());
    }

    /**
     * Open reminders of the owner's active vehicles, only the columns the
     * due counts need (one query on the status index; spec.md §8).
     *
     * @return list<OpenReminderRow>
     */
    public function listOpenForCounts(int $userId): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('vehicle_id', 'source', 'status', 'due_on', 'lead_time_days')
            ->from(self::TABLE)
            ->where('status IN (:open)')
            ->setParameter('open', self::openStatuses(), ArrayParameterType::STRING);
        $this->scopeToUser($query, $userId, true);

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

    public function find(int $userId, int $id): ?Reminder
    {
        $query = $this->select()->where('id = :id')->setParameter('id', $id, ParameterType::INTEGER);
        $this->scopeToUser($query, $userId, false);
        $row = $query->fetchAssociative();

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

    public function delete(int $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    /**
     * Take a reminder for sending in its current status. Succeeds for one
     * caller only, and only while the status still awaits notification.
     */
    public function claim(Reminder $reminder, DateTimeImmutable $now): bool
    {
        $affected = $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('notified_status', ':status')
            ->set('last_notified_at', ':now')
            ->where('id = :id', 'status = :status')
            ->andWhere('notified_status IS NULL OR notified_status <> :status')
            ->setParameter('status', $reminder->status->value)
            ->setParameter('now', $this->timestamp($now))
            ->setParameter('id', $reminder->id, ParameterType::INTEGER)
            ->executeStatement();

        return $affected === 1;
    }

    /**
     * Undo claim() after nothing could be delivered, so the next run retries.
     */
    public function release(Reminder $reminder): void
    {
        $previousAt = $reminder->lastNotifiedAt === null ? null : $this->timestamp($reminder->lastNotifiedAt);

        $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('notified_status', ':previous')
            ->set('last_notified_at', ':previous_at')
            ->where('id = :id', 'notified_status = :claimed')
            ->setParameter('previous', $reminder->notifiedStatus?->value)
            ->setParameter('previous_at', $previousAt)
            ->setParameter('id', $reminder->id, ParameterType::INTEGER)
            ->setParameter('claimed', $reminder->status->value)
            ->executeStatement();
    }

    /**
     * Add the channels that delivered a claimed reminder.
     *
     * @param list<string> $channels
     */
    public function recordDelivery(Reminder $reminder, array $channels): void
    {
        $all = array_values(array_unique([...$reminder->channelsNotified, ...$channels]));
        sort($all);

        $this->connection->update(
            self::TABLE,
            ['channels_notified' => $this->json($all)],
            ['id' => $reminder->id],
            ['id' => ParameterType::INTEGER],
        );
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'vehicle_id', 'source', 'source_id', 'occurrence', 'category', 'title', 'notes')
            ->addSelect('due_on', 'due_km', 'lead_time_days', 'status', 'notified_status', 'channels_notified')
            ->addSelect('last_notified_at', 'closed_at', 'created_at', 'updated_at')
            ->from(self::TABLE);
    }

    private function scopeToUser(QueryBuilder $query, int $userId, bool $activeVehiclesOnly): void
    {
        $vehicles = $this->connection->createQueryBuilder()
            ->select('v.id')
            ->from('vehicles', 'v')
            ->where('v.user_id = :user');
        if ($activeVehiclesOnly) {
            $vehicles->andWhere('v.status = :vehicle_status');
            $query->setParameter('vehicle_status', VehicleStatus::Active->value);
        }

        $query->andWhere('vehicle_id IN (' . $vehicles->getSQL() . ')')
            ->setParameter('user', $userId, ParameterType::INTEGER);
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
            'due_on' => $data->dueOn->format('Y-m-d'),
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
