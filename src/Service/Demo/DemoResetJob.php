<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Logbook\Service\Jobs\ConditionalJob;
use Logbook\Service\Jobs\JobContext;
use Logbook\Service\Jobs\JobResult;
use Logbook\Service\Jobs\NotOnPageVisits;
use Logbook\Service\Jobs\TimedJob;
use Logbook\Support\Config\AppSettings;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `demo_reset` (spec.md §7.36, §7.30): puts the sample data back every
 * `DEMO_RESET_HOURS`. Listed only while the demo is active, and never run
 * from a page visit, so a visitor's request does not wait for it.
 */
final readonly class DemoResetJob implements ConditionalJob, NotOnPageVisits, TimedJob
{
    public const string NAME = 'demo_reset';

    public function __construct(
        private DemoMode $mode,
        private DemoResetter $resetter,
        private AppSettings $settings,
        private Connection $connection,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function interval(): int
    {
        return $this->settings->demo->resetHours * 3600;
    }

    /**
     * Due once the interval has passed since the demo was last put back (the marker's
     * time), so a freshly seeded demo is not reset by the first pass.
     */
    public function dueAt(?DateTimeImmutable $lastStarted, DateTimeImmutable $now): DateTimeImmutable
    {
        $marker = $this->mode->status()->marker;
        $since = $marker !== null ? $marker->lastResetAt : ($lastStarted ?? $now->modify('-1 year'));

        return $since->modify(sprintf('+%d seconds', $this->interval()));
    }

    public function isListed(): bool
    {
        return $this->mode->isActive();
    }

    public function run(JobContext $context): JobResult
    {
        try {
            $this->resetter->reset();
        } catch (DemoResetRefused $refused) {
            return JobResult::failed($refused->getMessage());
        }

        $counts = [
            'vehicles' => $this->count('vehicles'),
            'fillups' => $this->count('fuel_entries'),
        ];

        return JobResult::ok($this->translator->trans('jobs.summary.demo_reset', $counts), $counts);
    }

    private function count(string $table): int
    {
        $count = $this->connection->createQueryBuilder()->select('COUNT(*)')->from($table)->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }
}
