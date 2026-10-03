<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\PriceAlert;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Price alerts (`price_alerts`, spec.md §6 PriceAlert, §7.34 *Price
 * alerts*), scoped to their user.
 */
final readonly class PriceAlertRepository
{
    private const string TABLE = 'price_alerts';
    private const int PRICE_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<PriceAlert>
     */
    public function forUser(int $userId): array
    {
        return $this->fetch($this->select()
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('station_id')
            ->addOrderBy('grade'));
    }

    /**
     * @return list<PriceAlert>
     */
    public function forStation(int $userId, int $stationId): array
    {
        return $this->fetch($this->select()
            ->where('user_id = :user', 'station_id = :station')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('station', $stationId, ParameterType::INTEGER)
            ->orderBy('grade'));
    }

    /**
     * Every alert on stations linked to a provider, for the check after a
     * sync, with the link's feed id.
     *
     * @return list<array{alert: PriceAlert, ref: string}>
     */
    public function forProvider(string $provider): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'a.id',
                'a.user_id',
                'a.station_id',
                'a.grade',
                'a.below',
                'a.triggered_at',
                'a.created_at',
                'a.updated_at',
                's.provider_ref',
            )
            ->from(self::TABLE, 'a')
            ->innerJoin('a', 'stations', 's', 's.id = a.station_id')
            ->where('s.provider = :provider', 's.provider_ref IS NOT NULL', 's.merged_into IS NULL')
            ->setParameter('provider', $provider)
            ->orderBy('a.id')
            ->fetchAllAssociative();
        $found = [];
        foreach ($rows as $row) {
            $alert = $this->hydrate($row);
            if ($alert !== null) {
                $found[] = ['alert' => $alert, 'ref' => Row::string($row, 'provider_ref')];
            }
        }

        return $found;
    }

    public function count(int $userId): int
    {
        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(self::TABLE)
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Set (or change) the user's alert for a station and grade; a changed
     * price re-arms it.
     */
    public function save(int $userId, int $stationId, FuelGrade $grade, string $below, DateTimeImmutable $now): void
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->transactional(function (
            Connection $connection,
        ) use (
            $userId,
            $stationId,
            $grade,
            $below,
            $timestamp,
        ): void {
            $updated = $connection->update(self::TABLE, [
                'below' => $below,
                'triggered_at' => null,
                'updated_at' => $timestamp,
            ], ['user_id' => $userId, 'station_id' => $stationId, 'grade' => $grade->value], [
                'user_id' => ParameterType::INTEGER,
                'station_id' => ParameterType::INTEGER,
            ]);
            if ((int) $updated === 0) {
                $connection->insert(self::TABLE, [
                    'user_id' => $userId,
                    'station_id' => $stationId,
                    'grade' => $grade->value,
                    'below' => $below,
                    'triggered_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ], ['user_id' => ParameterType::INTEGER, 'station_id' => ParameterType::INTEGER]);
            }
        });
    }

    public function delete(int $userId, int $stationId, ?FuelGrade $grade = null): void
    {
        $criteria = ['user_id' => $userId, 'station_id' => $stationId];
        if ($grade !== null) {
            $criteria['grade'] = $grade->value;
        }
        $this->connection->delete(self::TABLE, $criteria, [
            'user_id' => ParameterType::INTEGER,
            'station_id' => ParameterType::INTEGER,
        ]);
    }

    /**
     * Remove every user's alerts on a station (it was unlinked).
     */
    public function deleteForStation(int $stationId): void
    {
        $this->connection->delete(self::TABLE, ['station_id' => $stationId], ['station_id' => ParameterType::INTEGER]);
    }

    /**
     * Armed → triggered, only if still armed: true for the one run that
     * wins it (spec.md §7.34: claimed before sending).
     */
    public function claim(int $id, DateTimeImmutable $now): bool
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        return (int) $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('triggered_at', ':now')
            ->set('updated_at', ':now')
            ->where('id = :id', 'triggered_at IS NULL')
            ->setParameter('now', $timestamp)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement() === 1;
    }

    public function rearm(int $id, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'triggered_at' => null,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'user_id', 'station_id', 'grade', 'below', 'triggered_at', 'created_at', 'updated_at')
            ->from(self::TABLE);
    }

    /**
     * @return list<PriceAlert>
     */
    private function fetch(QueryBuilder $query): array
    {
        $alerts = [];
        foreach ($query->fetchAllAssociative() as $row) {
            $alert = $this->hydrate($row);
            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?PriceAlert
    {
        $grade = FuelGrade::tryFrom(Row::string($row, 'grade'));
        if ($grade === null) {
            return null;
        }
        $platform = $this->connection->getDatabasePlatform();
        $triggered = $row['triggered_at'] ?? null;

        return new PriceAlert(
            id: Row::int($row, 'id'),
            userId: Row::int($row, 'user_id'),
            stationId: Row::int($row, 'station_id'),
            grade: $grade,
            below: Row::decimal($row, 'below', self::PRICE_SCALE),
            triggeredAt: $triggered === null ? null : UtcDateTime::fromDatabase($triggered, $platform),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
