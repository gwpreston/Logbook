<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Job\JobRun;
use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Background job runs (`job_runs`, spec.md §6 JobRun, §7.30). Not backed
 * up; the `cleanup` job prunes them.
 */
final readonly class JobRunRepository
{
    private const string TABLE = 'job_runs';

    public function __construct(private Connection $connection)
    {
    }

    public function start(string $job, JobTrigger $trigger, ?int $userId, DateTimeImmutable $now): int
    {
        $this->connection->insert(self::TABLE, [
            'job' => $job,
            'trigger_kind' => $trigger->value,
            'user_id' => $userId,
            'started_at' => $this->time($now),
            'status' => JobStatus::Running->value,
            'output' => '',
        ], ['user_id' => $userId === null ? ParameterType::NULL : ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * A run that found its job locked: recorded as finished at once.
     */
    public function recordSkipped(
        string $job,
        JobTrigger $trigger,
        ?int $userId,
        DateTimeImmutable $now,
        string $summary,
        string $output,
    ): int {
        $this->connection->insert(self::TABLE, [
            'job' => $job,
            'trigger_kind' => $trigger->value,
            'user_id' => $userId,
            'started_at' => $this->time($now),
            'finished_at' => $this->time($now),
            'status' => JobStatus::SkippedLocked->value,
            'summary' => $summary,
            'output' => $output,
        ], ['user_id' => $userId === null ? ParameterType::NULL : ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * The output so far, while the run goes on.
     */
    public function writeOutput(int $id, string $output): void
    {
        $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('output', ':output')
            ->where('id = :id', 'status = :running')
            ->setParameter('output', $output)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->setParameter('running', JobStatus::Running->value)
            ->executeStatement();
    }

    public function finish(int $id, JobStatus $status, string $summary, string $output, DateTimeImmutable $now): void
    {
        $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('status', ':status')
            ->set('summary', ':summary')
            ->set('output', ':output')
            ->set('finished_at', ':now')
            ->where('id = :id')
            ->setParameter('status', $status->value)
            ->setParameter('summary', mb_substr($summary, 0, 255))
            ->setParameter('output', $output)
            ->setParameter('now', $this->time($now))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    public function find(int $id): ?JobRun
    {
        $row = $this->select()
            ->where('r.id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The job's newest `running` row, if any.
     */
    public function running(string $job): ?JobRun
    {
        return $this->first($this->select()
            ->where('r.job = :job', 'r.status = :running')
            ->setParameter('job', $job)
            ->setParameter('running', JobStatus::Running->value));
    }

    /**
     * Mark the job's `running` rows started before $before as
     * `interrupted` (their process died; the caller holds the job's lock).
     */
    public function interruptBefore(string $job, DateTimeImmutable $before, DateTimeImmutable $now, string $summary): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('status', ':interrupted')
            ->set('summary', ':summary')
            ->set('finished_at', ':now')
            ->where('job = :job', 'status = :running', 'started_at < :before')
            ->setParameter('interrupted', JobStatus::Interrupted->value)
            ->setParameter('summary', $summary)
            ->setParameter('now', $this->time($now))
            ->setParameter('job', $job)
            ->setParameter('running', JobStatus::Running->value)
            ->setParameter('before', $this->time($before))
            ->executeStatement();
    }

    /**
     * The job's newest run, whatever its status.
     */
    public function latest(string $job): ?JobRun
    {
        return $this->first($this->select()->where('r.job = :job')->setParameter('job', $job));
    }

    /**
     * The job's newest run that did (or tried to do) its work: what decides
     * whether it is due.
     */
    public function latestWorked(string $job): ?JobRun
    {
        return $this->first($this->select()
            ->where('r.job = :job', 'r.status IN (:statuses)')
            ->setParameter('job', $job)
            ->setParameter('statuses', $this->worked(), ArrayParameterType::STRING));
    }

    /**
     * The job's newest finished runs with an outcome (ok, partial or
     * failed), newest first: what a failure streak is judged by.
     *
     * @return list<JobRun>
     */
    public function latestOutcomes(string $job, int $limit): array
    {
        $rows = $this->select()
            ->where('r.job = :job', 'r.status IN (:statuses)')
            ->setParameter('job', $job)
            ->setParameter('statuses', [
                JobStatus::Ok->value,
                JobStatus::Partial->value,
                JobStatus::Failed->value,
            ], ArrayParameterType::STRING)
            ->setMaxResults($limit)
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * The newest finished run of a scheduler pass (any trigger but
     * `manual`): when the scheduler last ran.
     */
    public function lastPass(): ?JobRun
    {
        return $this->first($this->select()
            ->where('r.trigger_kind <> :manual', 'r.status <> :running')
            ->setParameter('manual', JobTrigger::Manual->value)
            ->setParameter('running', JobStatus::Running->value));
    }

    /**
     * The job's newest run after $afterId (the run a *Run now* just
     * started, for the page that waits for it).
     */
    public function newestAfter(string $job, int $afterId): ?JobRun
    {
        return $this->first($this->select()
            ->where('r.job = :job', 'r.id > :after')
            ->setParameter('job', $job)
            ->setParameter('after', $afterId, ParameterType::INTEGER));
    }

    /**
     * The run that held the job's lock when $skipped was recorded: the
     * job's newest earlier run that was not itself skipped.
     */
    public function holderOf(JobRun $skipped): ?JobRun
    {
        return $this->first($this->select()
            ->where('r.job = :job', 'r.id < :id', 'r.status <> :skipped')
            ->setParameter('job', $skipped->job)
            ->setParameter('id', $skipped->id, ParameterType::INTEGER)
            ->setParameter('skipped', JobStatus::SkippedLocked->value));
    }

    /**
     * @return list<string> every job with a run, by name
     */
    public function jobNames(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $job): string => is_string($job) ? $job : '',
            $this->connection->createQueryBuilder()
                ->select('DISTINCT job')
                ->from(self::TABLE)
                ->orderBy('job')
                ->fetchFirstColumn(),
        )));
    }

    public function newestId(): int
    {
        $id = $this->connection->createQueryBuilder()
            ->select('MAX(id)')
            ->from(self::TABLE)
            ->fetchOne();

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * @return list<JobRun> newest first
     */
    public function recent(int $limit): array
    {
        $rows = $this->select()->setMaxResults($limit)->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * Retention (the `cleanup` job): finished runs older than $before, and
     * all but each job's newest $keep runs.
     *
     * @return int runs deleted
     */
    public function prune(int $keep, DateTimeImmutable $before): int
    {
        $deleted = (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('started_at < :before', 'status <> :running')
            ->setParameter('before', $this->time($before))
            ->setParameter('running', JobStatus::Running->value)
            ->executeStatement();

        foreach ($this->jobNames() as $job) {
            $ids = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $this->connection
                ->createQueryBuilder()
                ->select('id')
                ->from(self::TABLE)
                ->where('job = :job', 'status <> :running')
                ->setParameter('job', $job)
                ->setParameter('running', JobStatus::Running->value)
                ->orderBy('started_at', 'DESC')
                ->addOrderBy('id', 'DESC')
                ->setFirstResult($keep)
                ->fetchFirstColumn());
            foreach (array_chunk($ids, 500) as $chunk) {
                $deleted += (int) $this->connection->createQueryBuilder()
                    ->delete(self::TABLE)
                    ->where('id IN (:ids)')
                    ->setParameter('ids', $chunk, ArrayParameterType::INTEGER)
                    ->executeStatement();
            }
        }

        return $deleted;
    }

    /**
     * Every run (a restore replaces the accounts they name).
     */
    public function deleteAll(): void
    {
        $this->connection->createQueryBuilder()->delete(self::TABLE)->executeStatement();
    }

    /**
     * @return list<string>
     */
    private function worked(): array
    {
        return array_values(array_map(
            static fn (JobStatus $s): string => $s->value,
            array_filter(JobStatus::cases(), static fn (JobStatus $s): bool => $s->didWork()),
        ));
    }

    private function first(QueryBuilder $query): ?JobRun
    {
        $row = $query->setMaxResults(1)->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'r.id',
                'r.job',
                'r.trigger_kind',
                'r.user_id',
                'r.started_at',
                'r.finished_at',
                'r.status',
                'r.summary',
                'r.output',
                'u.display_name',
            )
            ->from(self::TABLE, 'r')
            ->leftJoin('r', 'users', 'u', 'u.id = r.user_id')
            ->orderBy('r.started_at', 'DESC')
            ->addOrderBy('r.id', 'DESC');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): JobRun
    {
        $platform = $this->connection->getDatabasePlatform();
        $finished = $row['finished_at'] ?? null;

        return new JobRun(
            id: Row::int($row, 'id'),
            job: Row::string($row, 'job'),
            trigger: JobTrigger::tryFrom(Row::string($row, 'trigger_kind')) ?? JobTrigger::Manual,
            userId: Row::nullableInt($row, 'user_id'),
            startedAt: UtcDateTime::fromDatabase($row['started_at'] ?? null, $platform),
            finishedAt: $finished === null ? null : UtcDateTime::fromDatabase($finished, $platform),
            status: JobStatus::tryFrom(Row::string($row, 'status')) ?? JobStatus::Failed,
            summary: Row::nullableString($row, 'summary'),
            output: Row::nullableString($row, 'output') ?? '',
            userName: Row::nullableString($row, 'display_name'),
        );
    }

    private function time(DateTimeImmutable $value): string
    {
        return UtcDateTime::toDatabase($value, $this->connection->getDatabasePlatform());
    }
}
