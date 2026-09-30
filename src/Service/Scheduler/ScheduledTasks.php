<?php

declare(strict_types=1);

namespace Logbook\Service\Scheduler;

use Logbook\Domain\Feature\Feature;
use Logbook\Repository\UserRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Notification\ReminderNotifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Everything bin/run-scheduled-tasks.php does on each run (cron every 15
 * minutes, or the Docker entrypoint's loop; spec.md §10). One owner's
 * failure is logged and never stops the others. With the reminders module
 * switched off nothing is sent (spec.md §7.10).
 */
final readonly class ScheduledTasks
{
    public function __construct(
        private UserRepository $users,
        private ReminderNotifier $notifier,
        private FeatureToggles $features,
        private LoggerInterface $logger,
        private VehicleAccess $access,
    ) {
    }

    public function run(): TaskSummary
    {
        if (!$this->features->isEnabled(Feature::Reminders)) {
            $summary = new TaskSummary(0, 0, 0, 0);
            $this->logger->info('Scheduled tasks: the reminders module is switched off; nothing to send.');

            return $summary;
        }

        // Each run sees the vehicles as they are now, even in a long-lived process.
        $this->access->forget();
        $users = 0;
        $reminders = 0;
        $digests = 0;
        $failures = 0;

        foreach ($this->users->listAll() as $user) {
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

        $summary = new TaskSummary($users, $reminders, $digests, $failures);
        $this->logger->info('Scheduled tasks: {summary}', ['summary' => $summary->describe()]);

        return $summary;
    }
}
