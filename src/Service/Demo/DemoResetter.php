<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Logbook\Repository\BackupRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Storage\FileStorage;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the demo and puts it back (spec.md §7.36). The only code that
 * deletes data in demo mode, and it runs only when the guard says the
 * instance is a demo: the marker is present *and* `DEMO_MODE` is on.
 *
 * Both happen in one transaction on the app's connection, so a failure
 * leaves the old data (or, at the first start, an empty database to try
 * again). The marker is written last.
 */
final readonly class DemoResetter
{
    /**
     * Tables a reset leaves alone: the migration history, and the job runs
     * (the run that is resetting is one of them; its account links go empty
     * when the accounts are replaced).
     */
    public const array KEEP = ['phinxlog', 'job_runs'];

    /**
     * The tables restore leaves out of a backup (BackupRepository::EXCLUDED),
     * emptied before the backed-up ones, children first.
     */
    private const array EXCLUDED_ORDER = [
        'ai_feedback', 'ai_messages', 'ai_progress', 'ai_threads', 'ai_drafts', 'ai_insights', 'ai_busy',
        'ai_requests', 'ai_secrets', 'pending_uploads', 'sessions', 'invitations',
        'provider_prices', 'provider_stations', 'fuel_price_secrets',
    ];

    public function __construct(
        private Connection $connection,
        private DemoMode $mode,
        private DemoMarkers $markers,
        private SampleData $seeder,
        private FileStorage $files,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private AppSettings $config,
    ) {
    }

    /**
     * The tables a reset empties (every table but the keep-list), in the
     * order it empties them. The schema decides, so a table added later
     * cannot silently survive.
     *
     * @param list<string> $schema every table in the database
     * @return list<string>
     */
    public static function clearOrder(array $schema): array
    {
        $known = [...self::EXCLUDED_ORDER, ...array_reverse(BackupRepository::TABLES)];
        $order = array_values(array_filter($known, static fn (string $t): bool => in_array($t, $schema, true)));
        $unknown = array_diff($schema, $known, self::KEEP);

        // A table nobody ordered goes last, after the ones that may point at it have gone.
        return [...$order, ...array_values($unknown)];
    }

    /**
     * Seeds an empty database as a demo: the first start with `DEMO_MODE`.
     * Does nothing unless the guard says the database is empty and the
     * password is usable.
     */
    public function seedFirst(string $seededBy = 'DEMO_MODE'): bool
    {
        if ($this->mode->state() !== DemoState::NeedsSeed) {
            return false;
        }
        $now = $this->clock->now();
        $before = $this->storedFiles();
        try {
            $this->connection->transactional(function () use ($now, $seededBy): void {
                $this->seeder->seed($this->config->demo->password, $now);
                $this->markers->write(new DemoMarker($now, $now, $seededBy));
            });
        } catch (Throwable $e) {
            // The sample paperwork written before the failure belongs to nothing.
            $this->deleteFiles(array_diff($this->storedFiles(), $before));
            throw $e;
        }
        $this->mode->forget();
        $this->logger->info('Demo mode: the sample data was added to an empty database.');

        return true;
    }

    /**
     * Put the sample data back, with its dates relative to now.
     *
     * @throws DemoResetRefused when this is not a demo instance
     */
    public function reset(): DateTimeImmutable
    {
        $status = $this->mode->status();
        if (!$status->isActive() || $status->marker === null) {
            throw new DemoResetRefused(
                'This database was not seeded as a demo (or DEMO_MODE is off), so it is never reset.',
            );
        }

        $now = $this->clock->now();
        $marker = $status->marker;
        // The files there are now (the seeding adds the sample paperwork under new names).
        $before = $this->storedFiles();
        // Read before the transaction: its first statement must be a write, or SQLite refuses to
        // upgrade a read lock when a visitor's request is writing at that moment.
        $schema = array_map(
            static fn (string $name): string => strtolower($name),
            $this->connection->createSchemaManager()->listTableNames(),
        );
        try {
            $this->connection->transactional(function () use ($now, $marker, $schema): void {
                $this->clear($schema);
                $this->seeder->seed($this->config->demo->password, $now);
                $this->markers->write($marker->resetAt($now));
            });
        } catch (Throwable $e) {
            // The old data stays, with its files; what the failed seeding wrote goes.
            $this->deleteFiles(array_diff($this->storedFiles(), $before));
            throw $e;
        }
        $this->mode->forget();

        // After the commit: files are not part of the transaction, and a failed
        // reset must keep the old data and its files.
        $this->deleteFiles($before);
        $this->logger->info('Demo mode: the demo was reset.');

        return $now;
    }

    /**
     * @param list<string> $schema every table in the database
     */
    private function clear(array $schema): void
    {
        foreach (self::clearOrder($schema) as $table) {
            if ($table === 'settings') {
                // Everything but the proof that this is a demo.
                $this->connection->createQueryBuilder()
                    ->delete('settings')
                    ->where('NOT (scope = :scope AND owner_id = 0 AND name = :name)')
                    ->setParameter('scope', 'global')
                    ->setParameter('name', DemoMarker::SETTING)
                    ->executeStatement();

                continue;
            }
            $this->connection->createQueryBuilder()->delete($table)->executeStatement();
        }
    }

    /**
     * Every stored file (uploads, avatars, pending scans), as relative paths.
     *
     * @return list<string>
     */
    private function storedFiles(): array
    {
        $root = $this->files->root();

        return is_dir($root) ? FileStorage::storedFilesIn($root) : [];
    }

    /**
     * @param iterable<string> $relatives
     */
    private function deleteFiles(iterable $relatives): void
    {
        $root = $this->files->root();
        foreach ($relatives as $relative) {
            if (!@unlink($root . '/' . $relative)) {
                $this->logger->warning('Demo reset could not delete {file}.', ['file' => $relative]);
            }
        }
    }
}
