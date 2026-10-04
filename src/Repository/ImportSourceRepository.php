<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Where imported rows came from (`import_sources`, spec.md §6
 * ImportSource, Phase 31): the app and the row's own id in its export, per
 * target vehicle. A known id is *already imported*, whatever happened to
 * the entry since.
 */
final readonly class ImportSourceRepository
{
    private const string TABLE = 'import_sources';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * The source ids of $app already imported into the vehicle.
     *
     * @return array<string, true>
     */
    public function known(string $app, int $vehicleId): array
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('source_id')
            ->from(self::TABLE)
            ->where('app = :app', 'vehicle_id = :vehicle')
            ->setParameter('app', $app)
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->fetchFirstColumn();

        return array_fill_keys(array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $ids), true);
    }

    /**
     * The vehicle that holds the most of these source ids, if any (to
     * prefill the vehicle for a newer export of the same one).
     *
     * @param list<string> $sourceIds
     * @param list<int> $vehicleIds only these vehicles
     */
    public function vehicleHolding(string $app, array $sourceIds, array $vehicleIds): ?int
    {
        if ($sourceIds === [] || $vehicleIds === []) {
            return null;
        }
        $best = null;
        $bestCount = 0;
        foreach (array_chunk($sourceIds, 500) as $chunk) {
            $rows = $this->connection->createQueryBuilder()
                ->select('vehicle_id', 'COUNT(*) AS n')
                ->from(self::TABLE)
                ->where('app = :app', 'source_id IN (:ids)', 'vehicle_id IN (:vehicles)')
                ->groupBy('vehicle_id')
                ->setParameter('app', $app)
                ->setParameter('ids', $chunk, ArrayParameterType::STRING)
                ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER)
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $count = Row::int($row, 'n');
                if ($count > $bestCount) {
                    $best = Row::int($row, 'vehicle_id');
                    $bestCount = $count;
                }
            }
        }

        return $best;
    }

    public function record(
        string $app,
        string $sourceId,
        int $vehicleId,
        string $entityType,
        int $entityId,
        ?int $importedBy,
        DateTimeImmutable $now,
    ): void {
        $this->connection->insert(self::TABLE, [
            'app' => $app,
            'source_id' => mb_substr($sourceId, 0, 64),
            'vehicle_id' => $vehicleId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'imported_by' => $importedBy,
            'imported_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], [
            'vehicle_id' => ParameterType::INTEGER,
            'entity_id' => ParameterType::INTEGER,
        ]);
    }
}
