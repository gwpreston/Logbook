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
 * `digest` (spec.md §7.30): every pass, after `reminders`; it sends a
 * user's monthly digest on their first pass of a month (§7.11). With the
 * reminders module off nothing is sent.
 */
final readonly class DigestJob implements Job
{
    public const string NAME = 'digest';

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
            $context->logger->info('The reminders module is switched off; no digests.');

            return JobResult::ok(
                $this->translator->trans('jobs.summary.module_off'),
                ['users' => 0, 'sent' => 0, 'failures' => 0],
            );
        }

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
                if ($this->notifier->digest($user)) {
                    $sent++;
                    $context->logger->info('Sent the monthly digest to user {user}.', ['user' => $user->id]);
                }
            } catch (Throwable $e) {
                $failures++;
                $context->logger->error('The digest failed for user {user}: {message}', [
                    'user' => $user->id,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        $counts = ['users' => $users, 'sent' => $sent, 'failures' => $failures];
        $summary = $this->translator->trans('jobs.summary.digest', $counts);

        return $failures === 0 ? JobResult::ok($summary, $counts) : JobResult::partial($summary, $counts);
    }
}
