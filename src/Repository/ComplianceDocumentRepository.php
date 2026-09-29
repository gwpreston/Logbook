<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Compliance documents (`compliance_documents`). Every query is scoped to a
 * vehicle; callers pass a vehicle already checked to belong to the user.
 */
final readonly class ComplianceDocumentRepository
{
    private const string TABLE = 'compliance_documents';
    private const int MONEY_SCALE = 3;
    private const int KM_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<ComplianceDocument> in creation order
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
     * Every document of several vehicles (a vehicle has few).
     *
     * @param list<int> $vehicleIds
     * @return list<ComplianceDocument> in creation order
     */
    public function listForVehicles(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $rows = $this->select()
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function find(int $vehicleId, int $id): ?ComplianceDocument
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, ComplianceDocumentData $data, DateTimeImmutable $now): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), ['vehicle_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update the document in place (never a delete + insert: its id, and so
     * its attachments, must survive an edit).
     */
    public function update(int $vehicleId, int $id, ComplianceDocumentData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
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
            ->select('id', 'vehicle_id', 'type', 'title', 'provider', 'reference', 'start_on', 'expiry_on')
            ->addSelect('cost', 'odometer_km', 'notes', 'created_at', 'updated_at')
            ->from(self::TABLE);
    }

    /**
     * @return array<string, string|null>
     */
    private static function dataColumns(ComplianceDocumentData $data): array
    {
        return [
            'type' => $data->type->value,
            'title' => $data->title,
            'provider' => $data->provider,
            'reference' => $data->reference,
            'start_on' => $data->startOn?->format('Y-m-d'),
            'expiry_on' => $data->expiryOn?->format('Y-m-d'),
            'cost' => $data->cost,
            'odometer_km' => $data->odometerKm,
            'notes' => $data->notes,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ComplianceDocument
    {
        $platform = $this->connection->getDatabasePlatform();

        return new ComplianceDocument(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new ComplianceDocumentData(
                type: ComplianceType::tryFrom(Row::string($row, 'type')) ?? ComplianceType::Other,
                title: Row::nullableString($row, 'title'),
                provider: Row::nullableString($row, 'provider'),
                reference: Row::nullableString($row, 'reference'),
                startOn: Row::nullableDate($row, 'start_on'),
                expiryOn: Row::nullableDate($row, 'expiry_on'),
                cost: Row::decimal($row, 'cost', self::MONEY_SCALE),
                notes: Row::nullableString($row, 'notes'),
                odometerKm: Row::nullableDecimal($row, 'odometer_km', self::KM_SCALE),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
