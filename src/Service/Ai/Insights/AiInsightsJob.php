<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Insights;

use Logbook\Domain\Ai\ErrorCode;
use Logbook\Repository\AiInsightRepository;
use Logbook\Repository\SessionRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\Ask\AskAvailability;
use Logbook\Service\Jobs\Job;
use Logbook\Service\Jobs\JobContext;
use Logbook\Service\Jobs\JobResult;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `ai_insights` (spec.md §7.26 *AI insights*, §7.30): hourly while Ask is
 * set up (never otherwise), makes the
 * day's AI insights for each active user with AI on who has been here in
 * the last 30 days and has none for their today yet, a few per run (a time
 * budget, and the run's own end). A user whose AI is busy is left for the
 * next run; nothing is made for anyone else.
 */
final readonly class AiInsightsJob implements Job
{
    public const string NAME = 'ai_insights';
    /** Seconds a run spends making sets before leaving the rest to the next. */
    public const int BUDGET_SECONDS = 300;
    private const int RECENT_DAYS = 30;

    public function __construct(
        private AiInsightService $insights,
        private AskAvailability $availability,
        private AiInsightRepository $repository,
        private UserRepository $users,
        private SessionRepository $sessions,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Hourly while Ask is set up for the install; never otherwise.
     */
    public function interval(): ?int
    {
        return $this->availability->isSetUp() ? 3600 : null;
    }

    public function run(JobContext $context): JobResult
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', self::RECENT_DAYS));
        $recent = array_filter($this->sessions->lastActivityByUser(), static fn ($at): bool => $at >= $cutoff);
        $days = $this->repository->days();
        $started = hrtime(true);
        $made = 0;
        $left = 0;
        $failed = 0;

        foreach ($this->users->listAll() as $user) {
            if (!$user->isActive() || !isset($recent[$user->id]) || !$this->insights->isAvailable($user)) {
                continue;
            }
            if (($days[$user->id] ?? null) === $this->insights->today($user)) {
                continue;
            }
            if ($context->cancelled() || (hrtime(true) - $started) / 1e9 > self::BUDGET_SECONDS) {
                $left++;
                continue;
            }
            try {
                $set = $this->insights->generate($user);
                $made++;
                if ($set->error !== null) {
                    $failed++;
                    $context->logger->warning('AI insights for user {user} failed: {code}.', [
                        'user' => $user->id,
                        'code' => $set->error->value,
                    ]);
                }
            } catch (AiFailure $failure) {
                if ($failure->error === ErrorCode::Busy) {
                    $left++;
                    continue;
                }
                $failed++;
                $context->logger->warning('AI insights for user {user} could not start: {code}.', [
                    'user' => $user->id,
                    'code' => $failure->error->value,
                ]);
            }
        }

        $counts = ['made' => $made, 'left' => $left, 'failed' => $failed];
        $context->logger->info('{made} set(s) made, {failed} failed, {left} left for the next run.', $counts);
        $summary = $this->translator->trans('ai_insights.summary', ['made' => $made, 'left' => $left]);

        return $failed > 0 && $made === $failed ? JobResult::partial($summary, $counts) : JobResult::ok($summary, $counts);
    }
}
