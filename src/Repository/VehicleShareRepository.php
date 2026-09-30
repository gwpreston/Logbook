<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Access\VehicleGrant;
use Logbook\Domain\Access\VehicleShare;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Vehicle shares (`vehicle_shares`, spec.md §6 VehicleShare, §7.21), and
 * the one query behind the access policy: every vehicle a user owns or has
 * a share on.
 */
final readonly class VehicleShareRepository
{
    private const string TABLE = 'vehicle_shares';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Every vehicle the user owns or has a share on, in creation order.
     *
     * @return list<VehicleGrant>
     */
    public function grantsFor(int $userId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('v.id AS vehicle_id', 'v.status AS vehicle_status', 'v.user_id AS owner_id')
            ->addSelect('s.id', 's.user_id', 's.level', 's.can_see_costs', 's.notify', 's.created_at', 's.updated_at')
            ->from('vehicles', 'v')
            ->leftJoin('v', self::TABLE, 's', 's.vehicle_id = v.id AND s.user_id = :user')
            ->where('v.user_id = :user OR s.user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('v.id')
            ->fetchAllAssociative();

        return array_values(array_map(function (array $row) use ($userId): VehicleGrant {
            $owned = Row::int($row, 'owner_id') === $userId;

            return new VehicleGrant(
                vehicleId: Row::int($row, 'vehicle_id'),
                status: VehicleStatus::from(Row::string($row, 'vehicle_status')),
                owned: $owned,
                share: $owned || ($row['id'] ?? null) === null ? null : $this->hydrate($row),
            );
        }, $rows));
    }

    /**
     * @return list<VehicleShare> oldest first
     */
    public function listForVehicle(int $vehicleId): array
    {
        $rows = $this->select()
            ->where('vehicle_id = :vehicle')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * Which of these vehicles have at least one share.
     *
     * @param list<int> $vehicleIds
     * @return list<int>
     */
    public function sharedVehicleIds(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('DISTINCT vehicle_id')
            ->from(self::TABLE)
            ->where('vehicle_id IN (:ids)')
            ->setParameter('ids', $vehicleIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();

        return array_values(array_map(static fn (array $row): int => Row::int($row, 'vehicle_id'), $rows));
    }

    public function find(int $vehicleId, int $userId): ?VehicleShare
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'user_id = :user')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(
        int $vehicleId,
        int $userId,
        ShareLevel $level,
        bool $canSeeCosts,
        bool $notify,
        DateTimeImmutable $now,
    ): void {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'user_id' => $userId,
            'level' => $level->value,
            'can_see_costs' => $canSeeCosts || $level->alwaysSeesCosts(),
            'notify' => $notify,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], self::types());
    }

    public function update(
        int $vehicleId,
        int $userId,
        ShareLevel $level,
        bool $canSeeCosts,
        bool $notify,
        DateTimeImmutable $now,
    ): void {
        $this->connection->update(self::TABLE, [
            'level' => $level->value,
            'can_see_costs' => $canSeeCosts || $level->alwaysSeesCosts(),
            'notify' => $notify,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['vehicle_id' => $vehicleId, 'user_id' => $userId], self::types());
    }

    public function setNotify(int $vehicleId, int $userId, bool $notify, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'notify' => $notify,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['vehicle_id' => $vehicleId, 'user_id' => $userId], self::types());
    }

    public function delete(int $vehicleId, int $userId): void
    {
        $this->connection->delete(
            self::TABLE,
            ['vehicle_id' => $vehicleId, 'user_id' => $userId],
            ['vehicle_id' => ParameterType::INTEGER, 'user_id' => ParameterType::INTEGER],
        );
    }

    /**
     * @return array<string, ParameterType>
     */
    private static function types(): array
    {
        return [
            'vehicle_id' => ParameterType::INTEGER,
            'user_id' => ParameterType::INTEGER,
            'can_see_costs' => ParameterType::BOOLEAN,
            'notify' => ParameterType::BOOLEAN,
        ];
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'vehicle_id', 'user_id', 'level', 'can_see_costs', 'notify', 'created_at', 'updated_at')
            ->from(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): VehicleShare
    {
        $platform = $this->connection->getDatabasePlatform();
        $level = ShareLevel::from(Row::string($row, 'level'));

        return new VehicleShare(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            userId: Row::int($row, 'user_id'),
            level: $level,
            canSeeCosts: $level->alwaysSeesCosts() || Row::bool($row, 'can_see_costs'),
            notify: Row::bool($row, 'notify'),
            createdAt: UtcDateTime::fromDatabase($row['created_at'], $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'], $platform),
        );
    }
}
