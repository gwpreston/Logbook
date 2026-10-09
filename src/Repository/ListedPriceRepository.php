<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\PriceChange;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Each listed price change of a tracked station (`listed_price_changes`,
 * spec.md §6 ListedPriceChange, decided 2026-10-03, #144), keyed by the
 * provider and the feed's id so it outlives the provider tables. Backed up.
 */
final readonly class ListedPriceRepository
{
    private const string TABLE = 'listed_price_changes';
    private const int PRICE_SCALE = 3;

    public function __construct(private Connection $connection, private RequestReads $reads)
    {
    }

    /**
     * Record a change once (the same report again is ignored).
     *
     * @return bool whether it was new
     */
    public function record(string $provider, string $ref, PriceChange $change): bool
    {
        $platform = $this->connection->getDatabasePlatform();
        $reported = UtcDateTime::toDatabase($change->reportedAt, $platform);
        $exists = $this->connection->createQueryBuilder()
            ->select('1')
            ->from(self::TABLE)
            ->where('provider = :provider', 'provider_ref = :ref', 'grade = :grade', 'reported_at = :reported')
            ->setParameter('provider', $provider)
            ->setParameter('ref', $ref)
            ->setParameter('grade', $change->grade->value)
            ->setParameter('reported', $reported)
            ->fetchOne();
        if ($exists !== false) {
            return false;
        }
        try {
            $this->connection->insert(self::TABLE, [
                'provider' => $provider,
                'provider_ref' => $ref,
                'grade' => $change->grade->value,
                'price' => $change->price,
                'reported_at' => $reported,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * A station's changes, oldest first.
     *
     * @return list<PriceChange>
     */
    public function changes(string $provider, string $ref, ?FuelGrade $grade = null, ?DateTimeImmutable $since = null): array
    {
        $platform = $this->connection->getDatabasePlatform();
        $query = $this->connection->createQueryBuilder()
            ->select('grade', 'price', 'reported_at')
            ->from(self::TABLE)
            ->where('provider = :provider', 'provider_ref = :ref')
            ->setParameter('provider', $provider)
            ->setParameter('ref', $ref)
            ->orderBy('reported_at')
            ->addOrderBy('id');
        if ($grade !== null) {
            $query->andWhere('grade = :grade')->setParameter('grade', $grade->value);
        }
        if ($since !== null) {
            $query->andWhere('reported_at >= :since')->setParameter('since', UtcDateTime::toDatabase($since, $platform));
        }
        $changes = [];
        foreach ($query->fetchAllAssociative() as $row) {
            $code = FuelGrade::tryFrom(Row::string($row, 'grade'));
            if ($code !== null) {
                $changes[] = new PriceChange(
                    $code,
                    Row::decimal($row, 'price', self::PRICE_SCALE),
                    UtcDateTime::fromDatabase($row['reported_at'] ?? null, $platform),
                );
            }
        }

        return $changes;
    }

    /**
     * The price in effect at a moment: the latest change reported at or
     * before it (spec.md §7.34 *After a fill-up*).
     */
    public function priceAt(string $provider, string $ref, FuelGrade $grade, DateTimeImmutable $at): ?PriceChange
    {
        if ($this->reads->isActive()) {
            // A page compares many fill-ups at one station: its history is read once (spec.md §8 *Page budgets*).
            $found = null;
            $changes = $this->reads->remember(
                self::TABLE,
                $provider . '|' . $ref,
                fn (): array => $this->changes($provider, $ref),
            );
            foreach ($changes as $change) {
                // Oldest first (then by id), so the last one at or before $at wins, as the query's order.
                if ($change->grade === $grade && $change->reportedAt <= $at) {
                    $found = $change;
                }
            }

            return $found;
        }
        $platform = $this->connection->getDatabasePlatform();
        $row = $this->connection->createQueryBuilder()
            ->select('price', 'reported_at')
            ->from(self::TABLE)
            ->where('provider = :provider', 'provider_ref = :ref', 'grade = :grade', 'reported_at <= :at')
            ->setParameter('provider', $provider)
            ->setParameter('ref', $ref)
            ->setParameter('grade', $grade->value)
            ->setParameter('at', UtcDateTime::toDatabase($at, $platform))
            ->orderBy('reported_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->fetchAssociative();

        return $row === false ? null : new PriceChange(
            $grade,
            Row::decimal($row, 'price', self::PRICE_SCALE),
            UtcDateTime::fromDatabase($row['reported_at'] ?? null, $platform),
        );
    }

    /**
     * Drop changes reported before a time (PRICE_HISTORY_DAYS).
     */
    public function purge(DateTimeImmutable $before): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('reported_at < :before')
            ->setParameter('before', UtcDateTime::toDatabase($before, $this->connection->getDatabasePlatform()))
            ->executeStatement();
    }
}
