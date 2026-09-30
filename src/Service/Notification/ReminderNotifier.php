<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use DateTimeImmutable;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Repository\ReminderRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The reminder part of the scheduled task (spec.md §7.11), for one user:
 * sync the reminders they can see, send what has newly become due or
 * overdue on the vehicles they receive reminders for (their own, and those
 * shared with *Send me its reminders*), and the monthly digest when it is
 * time. Each run is the user's alone, in their language, units and time
 * zone, through the channels that reach them.
 *
 * Idempotent: each reminder is claimed for this user and its current
 * status (ReminderRepository::claim(), a row in `reminder_deliveries`)
 * before anything is sent, so a re-run, or a run overlapping this one,
 * never sends it to them again. If no channel delivers, their claims are
 * released for the next run to retry; other recipients are not affected.
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
        private VehicleAccess $access,
    ) {
    }

    public function run(User $user): NotifierReport
    {
        if (!$user->isActive()) {
            return new NotifierReport(0, false);
        }
        $this->sync->sync($user);

        $preferences = $this->settings->notificationPreferences($user->id);
        $recipient = Recipient::of($user, $preferences);
        if ($this->channels->active($preferences, $recipient) === []) {
            // Nothing can reach them. Leave everything unclaimed, so it is
            // sent once a channel is set up (if it is still due then).
            return new NotifierReport(0, false);
        }

        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

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
            $this->reminders->listAwaitingNotification($this->access->recipientVehicleIds($user), $user->id),
            fn (Reminder $r): bool => $this->reminders->claim($r, $user->id, $this->clock->now()),
        ));
        if ($claimed === []) {
            return 0;
        }

        // Entries leave out what must not be sent (an archived vehicle, a
        // switched-off module): release those claims straight away.
        $entries = $this->service->entries($user, $claimed)['open'];
        $sending = array_map(static fn (ReminderEntry $e): int => $e->reminder->id, $entries);
        $this->release($user, array_values(array_filter(
            $claimed,
            static fn (Reminder $r): bool => !in_array($r->id, $sending, true),
        )));
        $claimed = array_values(array_filter(
            $claimed,
            static fn (Reminder $r): bool => in_array($r->id, $sending, true),
        ));
        if ($entries === []) {
            return 0;
        }

        $report = $this->dispatcher->dispatch($this->composer->reminders($user, $entries, $today), $recipient, $preferences);
        if (!$report->anyDelivered()) {
            $this->release($user, $claimed);

            return 0;
        }

        foreach ($claimed as $reminder) {
            $this->reminders->recordDelivery($reminder, $user->id, $report->deliveredChannels(), $this->clock->now());
        }
        if ($report->failures() !== []) {
            $this->logger->warning('Reminders for user {user} were only partly delivered; failed channels are not retried.', [
                'user' => $user->id,
            ]);
        }

        return count($claimed);
    }

    /**
     * The monthly digest, on the first run of a month in the user's time
     * zone, of the vehicles they receive reminders for. A month with nothing
     * due counts as done; one whose digest could not be delivered is retried
     * on the next run.
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
            $this->service->entries($user, $this->reminders->listForVehicles($this->access->recipientVehicleIds($user)))['open'],
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
     * @param list<Reminder> $claimed
     */
    private function release(User $user, array $claimed): void
    {
        foreach ($claimed as $reminder) {
            $this->reminders->release($reminder, $user->id);
        }
    }
}
