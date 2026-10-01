<?php

declare(strict_types=1);

namespace Logbook\Service\Scheduler;

use Logbook\Domain\Feature\Feature;
use Logbook\Repository\UserRepository;
use Logbook\Service\Ai\AiHousekeeping;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Notification\ReminderNotifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Everything bin/run-scheduled-tasks.php does on each run (cron every 15
 * minutes, or the Docker entrypoint's loop; spec.md §10). One owner's
 * failure is logged and never stops the others. With the reminders module
 * switched off nothing is sent (spec.md §7.10). The AI usage log's
 * retention runs either way (spec.md §7.25).
 */
final readonly class ScheduledTasks
{
    public function __construct(
        private UserRepository $users,
        private ReminderNotifier $notifier,
        private FeatureToggles $features,
        private LoggerInterface $logger,
        private VehicleAccess $access,
        private AiHousekeeping $ai,
    ) {
    }

    public function run(): TaskSummary
    {
        $summary = $this->reminders()->withAiRowsDeleted($this->aiHousekeeping());
        $this->logger->info('Scheduled tasks: {summary}', ['summary' => $summary->describe()]);

        return $summary;
    }

    private function reminders(): TaskSummary
    {
        if (!$this->features->isEnabled(Feature::Reminders)) {
            $this->logger->info('Scheduled tasks: the reminders module is switched off; nothing to send.');

            return new TaskSummary(0, 0, 0, 0);
        }

        // Each run sees the vehicles as they are now, even in a long-lived process.
        $this->access->forget();
        $users = 0;
        $reminders = 0;
        $digests = 0;
        $failures = 0;

        foreach ($this->users->listAll() as $user) {
            if (!$user->isActive()) {
                continue;
            }
            $users++;
            try {
                $report = $this->notifier->run($user);
                $reminders += $report->remindersSent;
                $digests += $report->digestSent ? 1 : 0;
            } catch (Throwable $e) {
                $failures++;
                $this->logger->error('Scheduled reminders failed for user {user}: {message}', [
                    'user' => $user->id,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        return new TaskSummary($users, $reminders, $digests, $failures);
    }

    /**
     * AI usage-log retention, whatever the modules (spec.md §7.25); a
     * failure is logged and never stops the reminders.
     */
    private function aiHousekeeping(): int
    {
        try {
            return $this->ai->run();
        } catch (Throwable $e) {
            $this->logger->error('Scheduled AI housekeeping failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return 0;
        }
    }
}
