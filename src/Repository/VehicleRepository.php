<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\Disposal;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * The garage (`vehicles` table). Lookups by id are not scoped: the access
 * policy (spec.md §5) decides who reaches a vehicle, and writes are keyed by
 * the vehicle's own owner as well as its id.
 */
final readonly class VehicleRepository
{
    private const string TABLE = 'vehicles';
    private const int CAPACITY_SCALE = 3;
    private const int MONEY_SCALE = 3;

    public function __construct(private Connection $connection, private StoredGrade $grades)
    {
    }

    /**
     * The ids of one owner's vehicles, in creation order: the one query
     * behind the access policy (the user + status index).
     *
     * @param VehicleStatus|null $status null for every status
     * @return list<int>
     */
    public function idsOwnedBy(int $userId, ?VehicleStatus $status): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(self::TABLE)
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('id');
        if ($status !== null) {
            $query->andWhere('status = :status')->setParameter('status', $status->value);
        }

        return array_values(array_map(static fn (array $row): int => Row::int($row, 'id'), $query->fetchAllAssociative()));
    }

    /**
     * @param list<int> $ids
     * @return list<Vehicle> in creation order
     */
    public function listByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->select()
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * Whoever owns it: access is the policy's question (spec.md §5), asked
     * by the caller before anything is shown.
     */
    public function findById(int $id): ?Vehicle
    {
        $row = $this->select()
            ->where('id = :id')
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

    /**
     * Archive or restore. Restoring clears the disposal (spec.md §7.29
     * *Total loss*); the sale date and price stay.
     */
    public function setStatus(int $userId, int $id, VehicleStatus $status, DateTimeImmutable $now): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $timestamp = UtcDateTime::toDatabase($now, $platform);
        $columns = [
            'status' => $status->value,
            'archived_at' => $status === VehicleStatus::Archived ? $timestamp : null,
            'updated_at' => $timestamp,
        ];
        if ($status === VehicleStatus::Active) {
            $columns += ['disposal' => null, 'disposal_incident_id' => null];
        }

        $this->connection->update(
            self::TABLE,
            $columns,
            ['user_id' => $userId, 'id' => $id],
            ['user_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Archive as a total loss: written off, the incident, the settlement as
     * the sale, in one statement (spec.md §7.29 *Total loss*).
     *
     * @param string $salePrice canonical decimal
     */
    public function archiveWrittenOff(
        int $userId,
        int $id,
        ?int $incidentId,
        DateTimeImmutable $saleDate,
        string $salePrice,
        DateTimeImmutable $now,
    ): void {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->update(self::TABLE, [
            'status' => VehicleStatus::Archived->value,
            'archived_at' => $timestamp,
            'disposal' => Disposal::WrittenOff->value,
            'disposal_incident_id' => $incidentId,
            'sale_date' => $saleDate->format('Y-m-d'),
            'sale_price' => $salePrice,
            'updated_at' => $timestamp,
        ], ['user_id' => $userId, 'id' => $id], [
            'user_id' => ParameterType::INTEGER,
            'id' => ParameterType::INTEGER,
            'disposal_incident_id' => $incidentId === null ? ParameterType::NULL : ParameterType::INTEGER,
        ]);
    }

    /**
     * Mark the vehicle sold, or clear `sold` (null). A written-off vehicle
     * is left as it is: only Restore clears that.
     */
    public function setSold(int $userId, int $id, bool $sold): void
    {
        $query = $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->where('user_id = :user', 'id = :id')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER);
        if ($sold) {
            $query->set('disposal', ':sold')->andWhere('disposal IS NULL');
        } else {
            $query->set('disposal', 'NULL')->andWhere('disposal = :sold');
        }
        $query->setParameter('sold', Disposal::Sold->value)->executeStatement();
    }

    /**
     * Give the vehicle a new owner (spec.md §7.21 *Transfer*).
     */
    public function transfer(int $id, int $fromUserId, int $toUserId, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'user_id' => $toUserId,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['user_id' => $fromUserId, 'id' => $id], [
            'user_id' => ParameterType::INTEGER,
            'id' => ParameterType::INTEGER,
        ]);
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
                'variant',
                'year',
                'first_registered_on',
                'first_inspection_due_on',
                'registration',
                'vin',
                'fuel_type',
                'default_grade',
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
                'disposal',
                'disposal_incident_id',
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
            'variant' => $data->variant,
            'year' => $data->year,
            'first_registered_on' => $data->firstRegisteredOn?->format('Y-m-d'),
            'first_inspection_due_on' => $data->firstInspectionDueOn?->format('Y-m-d'),
            'registration' => $data->registration,
            'vin' => $data->vin,
            'fuel_type' => $data->fuelType->value,
            'default_grade' => $data->defaultGrade?->value,
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
        $id = Row::int($row, 'id');
        $fuelType = FuelType::from(Row::string($row, 'fuel_type'));

        return new Vehicle(
            id: $id,
            userId: Row::int($row, 'user_id'),
            data: new VehicleData(
                type: VehicleType::from(Row::string($row, 'type')),
                make: Row::string($row, 'make'),
                model: Row::string($row, 'model'),
                fuelType: $fuelType,
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
                defaultGrade: $this->grades->read(
                    Row::nullableString($row, 'default_grade'),
                    FuelGrade::defaultFamilyFor($fuelType),
                    self::TABLE,
                    $id,
                ),
                variant: Row::nullableString($row, 'variant'),
                firstRegisteredOn: Row::nullableDate($row, 'first_registered_on'),
                firstInspectionDueOn: Row::nullableDate($row, 'first_inspection_due_on'),
            ),
            status: VehicleStatus::from(Row::string($row, 'status')),
            photoPath: Row::nullableString($row, 'photo_path'),
            photoMime: Row::nullableString($row, 'photo_mime'),
            archivedAt: $archivedAt === null ? null : UtcDateTime::fromDatabase($archivedAt, $platform),
            createdAt: UtcDateTime::fromDatabase($row['created_at'], $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'], $platform),
            disposal: Disposal::tryFrom((string) Row::nullableString($row, 'disposal')),
            disposalIncidentId: Row::nullableInt($row, 'disposal_incident_id'),
        );
    }
}
