<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * The dates the History pages navigate by (spec.md §7.16): the first and
 * last entry of a kind, and the nearest one before or after a page. Each is
 * a MIN or MAX on the table's (vehicle_id, date) index, so finding the
 * neighbouring year costs the same after ten years of history as after one.
 * Only manual readings count (the others belong to their entries).
 *
 * Instants are returned in UTC; calendar dates at midnight UTC.
 */
final readonly class ActivityDateRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * The earliest and latest date of the source for these vehicles.
     *
     * @param list<int> $vehicleIds
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    public function span(DatedSource $source, array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [null, null];
        }
        $column = self::column($source);
        $row = $this->scoped($source, $vehicleIds)
            ->select('MIN(' . $column . ') AS first', 'MAX(' . $column . ') AS last')
            ->fetchAssociative();

        return $row === false ? [null, null] : [$this->value($source, $row, 'first'), $this->value($source, $row, 'last')];
    }

    /**
     * The latest date strictly before $bound.
     *
     * @param list<int> $vehicleIds
     */
    public function latestBefore(DatedSource $source, array $vehicleIds, DateTimeImmutable $bound): ?DateTimeImmutable
    {
        return $this->edge($source, $vehicleIds, 'MAX', '<', $bound);
    }

    /**
     * The earliest date on or after $bound.
     *
     * @param list<int> $vehicleIds
     */
    public function earliestFrom(DatedSource $source, array $vehicleIds, DateTimeImmutable $bound): ?DateTimeImmutable
    {
        return $this->edge($source, $vehicleIds, 'MIN', '>=', $bound);
    }

    /**
     * @param list<int> $vehicleIds
     */
    private function edge(
        DatedSource $source,
        array $vehicleIds,
        string $aggregate,
        string $operator,
        DateTimeImmutable $bound,
    ): ?DateTimeImmutable {
        if ($vehicleIds === []) {
            return null;
        }
        $column = self::column($source);
        $row = $this->scoped($source, $vehicleIds)
            ->select($aggregate . '(' . $column . ') AS edge')
            ->andWhere($column . ' ' . $operator . ' :bound')
            ->setParameter('bound', $source->isInstant()
                ? UtcDateTime::toDatabase($bound, $this->connection->getDatabasePlatform())
                : $bound->format('Y-m-d'))
            ->fetchAssociative();

        return $row === false ? null : $this->value($source, $row, 'edge');
    }

    /**
     * @param list<int> $vehicleIds
     */
    private function scoped(DatedSource $source, array $vehicleIds): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->from(self::table($source))
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER);
        if ($source === DatedSource::Reading) {
            $query->andWhere('source = :manual')->setParameter('manual', OdometerSource::Manual->value);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function value(DatedSource $source, array $row, string $column): ?DateTimeImmutable
    {
        $value = $row[$column] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return $source->isInstant()
            ? UtcDateTime::fromDatabase($value, $this->connection->getDatabasePlatform())
            : Row::nullableDate($row, $column);
    }

    private static function table(DatedSource $source): string
    {
        return match ($source) {
            DatedSource::Fuel => 'fuel_entries',
            DatedSource::Reading => 'odometer_readings',
            DatedSource::Maintenance => 'maintenance_entries',
            DatedSource::Expense => 'expense_entries',
            DatedSource::TyreChange => 'tyre_changes',
            DatedSource::Valuation => 'vehicle_valuations',
            DatedSource::Trip => 'trips',
            DatedSource::Incident => 'incidents',
        };
    }

    private static function column(DatedSource $source): string
    {
        return match ($source) {
            DatedSource::Fuel => 'filled_at',
            DatedSource::Reading => 'recorded_at',
            DatedSource::Maintenance => 'performed_on',
            DatedSource::Expense => 'spent_on',
            DatedSource::TyreChange => 'done_on',
            DatedSource::Valuation => 'valued_on',
            DatedSource::Trip => 'travelled_on',
            DatedSource::Incident => 'occurred_on',
        };
    }
}
