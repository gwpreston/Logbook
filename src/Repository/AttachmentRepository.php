<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Attachment metadata (`attachments`); the files are in FileStorage. Every
 * query is scoped to a vehicle already checked to belong to the user.
 */
final readonly class AttachmentRepository
{
    private const string TABLE = 'attachments';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<Attachment> every attachment of the vehicle, oldest first
     */
    public function listForVehicle(int $vehicleId): array
    {
        $rows = $this->select()
            ->where('vehicle_id = :vehicle')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->orderBy('uploaded_at')
            ->addOrderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * @return list<Attachment> oldest first
     */
    public function listForOwner(int $vehicleId, AttachmentOwner $type, int $ownerId): array
    {
        $rows = $this->select()
            ->where('vehicle_id = :vehicle', 'owner_type = :type', 'owner_id = :owner')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('type', $type->value)
            ->setParameter('owner', $ownerId, ParameterType::INTEGER)
            ->orderBy('uploaded_at')
            ->addOrderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function find(int $vehicleId, int $id): ?Attachment
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
        AttachmentOwner $type,
        int $ownerId,
        string $filename,
        string $mime,
        int $size,
        string $storedPath,
        DateTimeImmutable $now,
    ): int {
        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'owner_type' => $type->value,
            'owner_id' => $ownerId,
            'filename' => $filename,
            'mime' => $mime,
            'size' => $size,
            'stored_path' => $storedPath,
            'uploaded_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['vehicle_id' => ParameterType::INTEGER, 'owner_id' => ParameterType::INTEGER, 'size' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function delete(int $vehicleId, int $id): void
    {
        $this->connection->delete(
            self::TABLE,
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'vehicle_id', 'owner_type', 'owner_id', 'filename', 'mime', 'size', 'stored_path', 'uploaded_at')
            ->from(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Attachment
    {
        return new Attachment(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            ownerType: AttachmentOwner::from(Row::string($row, 'owner_type')),
            ownerId: Row::int($row, 'owner_id'),
            filename: Row::string($row, 'filename'),
            mime: Row::string($row, 'mime'),
            size: Row::int($row, 'size'),
            storedPath: Row::string($row, 'stored_path'),
            uploadedAt: UtcDateTime::fromDatabase($row['uploaded_at'] ?? null, $this->connection->getDatabasePlatform()),
        );
    }
}
