<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Domain\Feature\Feature;
use Logbook\Repository\UserRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Notification\ReminderNotifier;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * `reminders` (spec.md §7.30): every pass, sync each active user's
 * reminders and send what became due (§7.6, §7.11). One user's failure is
 * logged and never stops the others; it makes the run `partial`. With the
 * reminders module off nothing is sent (§7.10).
 */
final readonly class RemindersJob implements Job
{
    public const string NAME = 'reminders';

    public function __construct(
        private UserRepository $users,
        private ReminderNotifier $notifier,
        private FeatureToggles $features,
        private VehicleAccess $access,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function interval(): int
    {
        return 0;
    }

    public function run(JobContext $context): JobResult
    {
        if (!$this->features->isEnabled(Feature::Reminders)) {
            $context->logger->info('The reminders module is switched off; nothing to send.');

            return JobResult::ok(
                $this->translator->trans('jobs.summary.module_off'),
                ['users' => 0, 'sent' => 0, 'failures' => 0],
            );
        }

        // Each run sees the vehicles as they are now, even in a long-lived process.
        $this->access->forget();
        $users = 0;
        $sent = 0;
        $failures = 0;
        foreach ($this->users->listAll() as $user) {
            if (!$user->isActive()) {
                continue;
            }
            if ($context->cancelled()) {
                $context->logger->warning('Out of time; the remaining accounts wait for the next run.');
                $failures++;
                break;
            }
            $users++;
            try {
                $count = $this->notifier->reminders($user);
                $sent += $count;
                if ($count > 0) {
                    $context->logger->info('Sent {count} reminder(s) to user {user}.', ['count' => $count, 'user' => $user->id]);
                }
            } catch (Throwable $e) {
                $failures++;
                $context->logger->error('Scheduled reminders failed for user {user}: {message}', [
                    'user' => $user->id,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        $counts = ['users' => $users, 'sent' => $sent, 'failures' => $failures];
        $summary = $this->translator->trans('jobs.summary.reminders', $counts);

        return $failures === 0 ? JobResult::ok($summary, $counts) : JobResult::partial($summary, $counts);
    }
}
