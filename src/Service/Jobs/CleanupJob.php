<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Repository\InvitationRepository;
use Logbook\Repository\JobRunRepository;
use Logbook\Repository\ListedPriceRepository;
use Logbook\Service\Ai\AiHousekeeping;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * `cleanup` (spec.md §7.30): hourly (decided 2026-10-02, #108), whatever
 * the modules. The AI usage log, locks, progress, drafts, unclaimed scans
 * and Ask threads (§7.25–§7.27); invitation links closed over 90 days ago
 * (#109); job runs past their 90 days or each job's last 50. One part's
 * failure never stops the others.
 */
final readonly class CleanupJob implements Job
{
    public const string NAME = 'cleanup';
    public const int INTERVAL = 3600;
    public const int INVITATION_DAYS = 90;
    public const int RUN_DAYS = 90;
    public const int RUNS_KEPT = 50;

    public function __construct(
        private AiHousekeeping $ai,
        private InvitationRepository $invitations,
        private JobRunRepository $runs,
        private ListedPriceRepository $listedPrices,
        private AppSettings $app,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function interval(): int
    {
        return self::INTERVAL;
    }

    public function run(JobContext $context): JobResult
    {
        $now = $this->clock->now();
        $counts = ['usage' => 0, 'drafts' => 0, 'scans' => 0, 'threads' => 0, 'invitations' => 0, 'runs' => 0, 'prices' => 0];
        $failures = 0;

        $parts = [
            'AI housekeeping' => function () use (&$counts): void {
                $counts = $this->ai->sweep() + $counts;
            },
            'invitations' => function () use (&$counts, $now): void {
                $counts['invitations'] = $this->invitations->deleteClosedBefore(
                    $now->modify(sprintf('-%d days', self::INVITATION_DAYS)),
                );
            },
            'job runs' => function () use (&$counts, $now): void {
                $counts['runs'] = $this->runs->prune(self::RUNS_KEPT, $now->modify(sprintf('-%d days', self::RUN_DAYS)));
            },
            // Phase 30.2: listed price changes past PRICE_HISTORY_DAYS (spec.md §7.34).
            'listed prices' => function () use (&$counts, $now): void {
                $counts['prices'] = $this->listedPrices->purge(
                    $now->modify(sprintf('-%d days', $this->app->priceHistoryDays)),
                );
            },
        ];
        foreach ($parts as $part => $work) {
            try {
                $work();
            } catch (Throwable $e) {
                $failures++;
                $context->logger->error('Cleanup of {part} failed: {message}', [
                    'part' => $part,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
        $context->logger->debug(
            'Deleted {usage} AI usage row(s), {drafts} draft(s), {scans} unclaimed scan(s), {threads} Ask thread(s), '
                . '{invitations} invitation(s), {runs} job run(s), {prices} listed price change(s).',
            $counts,
        );

        $deleted = array_filter($counts);
        $summary = $deleted === []
            ? $this->translator->trans('jobs.summary.cleanup_nothing')
            : implode(', ', array_map(
                fn (string $what, int $count): string => $this->translator->trans(
                    'jobs.summary.cleanup_item.' . $what,
                    ['count' => $count],
                ),
                array_keys($deleted),
                $deleted,
            ));
        $summary = $deleted === [] ? $summary : $this->translator->trans('jobs.summary.cleanup', ['list' => $summary]);
        $counts['failures'] = $failures;

        return match (true) {
            $failures === count($parts) => JobResult::failed($summary, $counts),
            $failures > 0 => JobResult::partial($summary, $counts),
            default => JobResult::ok($summary, $counts),
        };
    }
}
