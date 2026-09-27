<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * The garage (`vehicles` table). Every lookup is scoped to the owner, so one
 * user can never reach another's vehicle by guessing an id.
 */
final readonly class VehicleRepository
{
    private const string TABLE = 'vehicles';
    private const int CAPACITY_SCALE = 3;
    private const int MONEY_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<Vehicle> in creation order
     */
    public function listForUser(int $userId, bool $includeArchived): array
    {
        $query = $this->select()
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('id');

        if (!$includeArchived) {
            $query->andWhere('status = :status')->setParameter('status', VehicleStatus::Active->value);
        }

        return array_values(array_map($this->hydrate(...), $query->fetchAllAssociative()));
    }

    public function countByStatus(int $userId, VehicleStatus $status): int
    {
        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(self::TABLE)
            ->where('user_id = :user', 'status = :status')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('status', $status->value)
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function find(int $userId, int $id): ?Vehicle
    {
        $row = $this->select()
            ->where('user_id = :user', 'id = :id')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $userId, VehicleData $data, DateTimeImmutable $now): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'user_id' => $userId,
            'status' => VehicleStatus::Active->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), ['user_id' => ParameterType::INTEGER, 'year' => self::intType($data->year)]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $userId, int $id, VehicleData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['user_id' => $userId, 'id' => $id],
            ['user_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER, 'year' => self::intType($data->year)],
        );
    }

    public function setStatus(int $userId, int $id, VehicleStatus $status, DateTimeImmutable $now): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $timestamp = UtcDateTime::toDatabase($now, $platform);

        $this->connection->update(self::TABLE, [
            'status' => $status->value,
            'archived_at' => $status === VehicleStatus::Archived ? $timestamp : null,
            'updated_at' => $timestamp,
        ], ['user_id' => $userId, 'id' => $id], ['user_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER]);
    }

    public function setPhoto(int $userId, int $id, ?string $path, ?string $mime, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'photo_path' => $path,
            'photo_mime' => $mime,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['user_id' => $userId, 'id' => $id], ['user_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER]);
    }

    public function delete(int $userId, int $id): void
    {
        $this->connection->delete(
            self::TABLE,
            ['user_id' => $userId, 'id' => $id],
            ['user_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'user_id',
                'nickname',
                'type',
                'make',
                'model',
                'year',
                'registration',
                'vin',
                'fuel_type',
                'capacity',
                'currency',
                'photo_path',
                'photo_mime',
                'purchase_date',
                'purchase_price',
                'sale_date',
                'sale_price',
                'status',
                'archived_at',
                'created_at',
                'updated_at',
            )
            ->from(self::TABLE);
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function dataColumns(VehicleData $data): array
    {
        return [
            'nickname' => $data->nickname,
            'type' => $data->type->value,
            'make' => $data->make,
            'model' => $data->model,
            'year' => $data->year,
            'registration' => $data->registration,
            'vin' => $data->vin,
            'fuel_type' => $data->fuelType->value,
            'capacity' => $data->capacity,
            'currency' => $data->currency,
            'purchase_date' => $data->purchaseDate?->format('Y-m-d'),
            'purchase_price' => $data->purchasePrice,
            'sale_date' => $data->saleDate?->format('Y-m-d'),
            'sale_price' => $data->salePrice,
        ];
    }

    private static function intType(?int $value): ParameterType
    {
        return $value === null ? ParameterType::NULL : ParameterType::INTEGER;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Vehicle
    {
        $platform = $this->connection->getDatabasePlatform();
        $archivedAt = Row::nullableString($row, 'archived_at');

        return new Vehicle(
            id: Row::int($row, 'id'),
            userId: Row::int($row, 'user_id'),
            data: new VehicleData(
                type: VehicleType::from(Row::string($row, 'type')),
                make: Row::string($row, 'make'),
                model: Row::string($row, 'model'),
                fuelType: FuelType::from(Row::string($row, 'fuel_type')),
                nickname: Row::nullableString($row, 'nickname'),
                year: Row::nullableInt($row, 'year'),
                registration: Row::nullableString($row, 'registration'),
                vin: Row::nullableString($row, 'vin'),
                capacity: Row::nullableDecimal($row, 'capacity', self::CAPACITY_SCALE),
                currency: Row::nullableString($row, 'currency'),
                purchaseDate: Row::nullableDate($row, 'purchase_date'),
                purchasePrice: Row::nullableDecimal($row, 'purchase_price', self::MONEY_SCALE),
                saleDate: Row::nullableDate($row, 'sale_date'),
                salePrice: Row::nullableDecimal($row, 'sale_price', self::MONEY_SCALE),
            ),
            status: VehicleStatus::from(Row::string($row, 'status')),
            photoPath: Row::nullableString($row, 'photo_path'),
            photoMime: Row::nullableString($row, 'photo_mime'),
            archivedAt: $archivedAt === null ? null : UtcDateTime::fromDatabase($archivedAt, $platform),
            createdAt: UtcDateTime::fromDatabase($row['created_at'], $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'], $platform),
        );
    }
}
