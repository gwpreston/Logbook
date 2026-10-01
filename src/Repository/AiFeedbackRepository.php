<?php

declare(strict_types=1);

namespace Logbook\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Ai\Ask\FeedbackMark;
use Logbook\Support\Database\Row;

/**
 * Counts of *Helpful* and *Not right* per month (`ai_feedback`, spec.md §6
 * AiFeedback). They outlive the threads the marks were made on.
 */
final readonly class AiFeedbackRepository
{
    private const string TABLE = 'ai_feedback';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Add $by (1, or -1 when a mark is changed) to the month's count.
     */
    public function count(string $month, FeedbackMark $mark, int $by = 1): void
    {
        if ($this->bump($month, $mark, $by) > 0 || $by < 0) {
            return;
        }
        try {
            $this->connection->insert(self::TABLE, ['month' => $month, 'mark' => $mark->value, 'total' => $by], [
                'total' => ParameterType::INTEGER,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request inserted the row first.
            $this->bump($month, $mark, $by);
        }
    }

    /**
     * @return array<string, int> mark → count for the month
     */
    public function forMonth(string $month): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('mark', 'total')
            ->from(self::TABLE)
            ->where('month = :month')
            ->setParameter('month', $month)
            ->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $counts[Row::string($row, 'mark')] = Row::int($row, 'total');
        }

        return $counts;
    }

    private function bump(string $month, FeedbackMark $mark, int $by): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('total', $by < 0 ? 'CASE WHEN total > 0 THEN total - 1 ELSE 0 END' : 'total + 1')
            ->where('month = :month', 'mark = :mark')
            ->setParameter('month', $month)
            ->setParameter('mark', $mark->value)
            ->executeStatement();
    }
}
