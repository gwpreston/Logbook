<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use DateTimeImmutable;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Repository\ReminderRepository;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The reminder part of the scheduled task (spec.md §7.11), for one owner:
 * sync their reminders, send whatever has newly become due or overdue, and
 * the monthly digest when it is time.
 *
 * Idempotent: each reminder is claimed (ReminderRepository::claim()) for
 * its current status before anything is sent, so a re-run — or a run
 * overlapping this one — never sends it again. If no channel delivers, the
 * claims are released for the next run to retry.
 */
final readonly class ReminderNotifier
{
    public function __construct(
        private ReminderSync $sync,
        private ReminderRepository $reminders,
        private ReminderService $service,
        private ReminderSettingsStore $settings,
        private NotificationComposer $composer,
        private NotificationDispatcher $dispatcher,
        private ChannelRegistry $channels,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function run(User $user): NotifierReport
    {
        $this->sync->sync($user);

        $preferences = $this->settings->notificationPreferences($user->id);
        if ($this->channels->active($preferences) === []) {
            // Nothing can be delivered. Leave everything unclaimed, so it is
            // sent once a channel is set up (if it is still due then).
            return new NotifierReport(0, false);
        }

        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $recipient = new Recipient($user->id, $user->displayName, $preferences->email);

        return new NotifierReport(
            $this->sendDue($user, $recipient, $preferences, $today),
            $this->sendDigest($user, $recipient, $preferences, $today),
        );
    }

    /**
     * @return int how many reminders were delivered
     */
    private function sendDue(
        User $user,
        Recipient $recipient,
        NotificationPreferences $preferences,
        DateTimeImmutable $today,
    ): int {
        $claimed = array_values(array_filter(
            $this->reminders->listAwaitingNotification($user->id),
            fn (Reminder $r): bool => $this->reminders->claim($r, $this->clock->now()),
        ));
        if ($claimed === []) {
            return 0;
        }

        $entries = $this->service->entries($user, $claimed)['open'];
        if ($entries === []) {
            $this->release($claimed);

            return 0;
        }

        $report = $this->dispatcher->dispatch($this->composer->reminders($user, $entries, $today), $recipient, $preferences);
        if (!$report->anyDelivered()) {
            $this->release($claimed);

            return 0;
        }

        foreach ($claimed as $reminder) {
            $this->reminders->recordDelivery($reminder, $report->deliveredChannels());
        }
        if ($report->failures() !== []) {
            $this->logger->warning('Reminders for user {user} were only partly delivered; failed channels are not retried.', [
                'user' => $user->id,
            ]);
        }

        return count($claimed);
    }

    /**
     * The monthly digest, on the first run of a month in the owner's time
     * zone. A month with nothing due counts as done; one whose digest could
     * not be delivered is retried on the next run.
     */
    private function sendDigest(
        User $user,
        Recipient $recipient,
        NotificationPreferences $preferences,
        DateTimeImmutable $today,
    ): bool {
        $month = $today->format('Y-m');
        if (!$preferences->digest || $this->settings->digestMonth($user->id) === $month) {
            return false;
        }

        $endOfMonth = $today->modify('last day of this month');
        $entries = array_values(array_filter(
            $this->service->entries($user, $this->reminders->listForUser($user->id))['open'],
            static fn (ReminderEntry $e): bool => $e->reminder->status === ReminderStatus::Overdue
                || ($e->reminder->dueOn !== null && $e->reminder->dueOn <= $endOfMonth),
        ));
        if ($entries === []) {
            $this->settings->markDigestSent($user->id, $month);

            return false;
        }

        $report = $this->dispatcher->dispatch($this->composer->digest($user, $entries, $today), $recipient, $preferences);
        if (!$report->anyDelivered()) {
            return false;
        }

        $this->settings->markDigestSent($user->id, $month);

        return true;
    }

    /**
     * @param list<Reminder> $claimed as they were before claim()
     */
    private function release(array $claimed): void
    {
        foreach ($claimed as $reminder) {
            $this->reminders->release($reminder);
        }
    }
}
