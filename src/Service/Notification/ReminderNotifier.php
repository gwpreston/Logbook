<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use DateTimeImmutable;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Attention\AttentionList;
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
 * time. The `reminders` and `digest` jobs call one each (Phase
 * 28.1). Each run is the user's alone, in their language, units and time
 * zone, through the channels that reach them.
 *
 * Idempotent: each reminder is claimed for this user and its current
 * status (ReminderRepository::claim(), a row in `reminder_deliveries`)
 * before anything is sent, so a re-run, or a run overlapping this one,
 * never sends it to them again. If no channel delivers, their claims are
 * released for the next run to retry; other recipients are not affected.
 *
 * From Phase 36.4 (spec.md §7.11 *What each channel receives, and quiet
 * hours*): each channel gets the reminders it takes, and one is claimed
 * only while some channel takes its category. Inside the user's quiet
 * hours nothing is claimed and no digest is sent: the first run after
 * sends what still applies then.
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
        private AttentionList $attention,
        private VehicleRepository $vehicles,
    ) {
    }

    /**
     * The `reminders` job's work for one user (spec.md §7.30): sync, then
     * send what has newly become due or overdue.
     *
     * @return int how many reminders were delivered
     */
    public function reminders(User $user): int
    {
        if (!$user->isActive()) {
            return 0;
        }
        $this->sync->sync($user);

        $preferences = $this->settings->notificationPreferences($user->id);
        if ($this->isQuiet($user, $preferences)) {
            return 0;
        }
        $recipient = Recipient::of($user);
        $taken = array_values(array_filter(
            [NotificationCategory::Due, NotificationCategory::Overdue],
            fn (NotificationCategory $c): bool => $this->channels->active($preferences, $recipient, $c) !== [],
        ));
        if ($taken === []) {
            // Nothing can reach them. Leave everything unclaimed, so it is
            // sent once a channel is set up (if it is still due then).
            return 0;
        }

        return $this->sendDue($user, $recipient, $preferences, $taken, LocalTime::today($this->clock, $user->preferences->timeZone()));
    }

    /**
     * The `digest` job's work for one user: the monthly digest, when it is
     * time. It syncs the user's reminders first, as the combined task did,
     * unless this month's digest is already done (or switched off).
     *
     * @return bool whether a digest was delivered
     */
    public function digest(User $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }
        $preferences = $this->settings->notificationPreferences($user->id);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        if (!$preferences->digest || $this->settings->digestMonth($user->id) === $today->format('Y-m')) {
            return false;
        }
        // Held (not marked done) in quiet hours or while no channel takes it: a later run sends it.
        $recipient = Recipient::of($user);
        if ($this->isQuiet($user, $preferences) || $this->channels->active($preferences, $recipient, NotificationCategory::Digest) === []) {
            return false;
        }
        $this->sync->sync($user);

        return $this->sendDigest($user, $recipient, $preferences, $today);
    }

    private function isQuiet(User $user, NotificationPreferences $preferences): bool
    {
        return $preferences->quiet?->contains($this->clock->now(), $user->preferences->timeZone()) === true;
    }

    /**
     * @param non-empty-list<NotificationCategory> $taken the reminder categories some channel takes
     * @return int how many reminders were delivered
     */
    private function sendDue(
        User $user,
        Recipient $recipient,
        NotificationPreferences $preferences,
        array $taken,
        DateTimeImmutable $today,
    ): int {
        // A reminder no channel takes is left unclaimed, as with no channel at all.
        $claimed = array_values(array_filter(
            $this->reminders->listAwaitingNotification($this->access->recipientVehicleIds($user), $user->id),
            fn (Reminder $r): bool => in_array(NotificationCategory::forReminder($r->status), $taken, true)
                && $this->reminders->claim($r, $user->id, $this->clock->now()),
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

        $report = $this->dispatcher->dispatchReminders(
            $this->composer->reminderMessages($user, $entries, $today),
            $recipient,
            $preferences,
        );

        // Each reminder by the channels that delivered its category: none, and it is retried.
        $delivered = 0;
        $undelivered = [];
        foreach ($claimed as $reminder) {
            $channels = $report->deliveredChannelsFor(NotificationCategory::forReminder($reminder->status));
            if ($channels === []) {
                $undelivered[] = $reminder;
                continue;
            }
            $this->reminders->recordDelivery($reminder, $user->id, $channels, $this->clock->now());
            $delivered++;
        }
        $this->release($user, $undelivered);
        if ($delivered > 0 && $report->failures() !== []) {
            $this->logger->warning('Reminders for user {user} were only partly delivered; failed channels are not retried.', [
                'user' => $user->id,
            ]);
        }

        return $delivered;
    }

    /**
     * The monthly digest, on the first run of a month in the user's time
     * zone, of the vehicles they receive reminders for: what is due, then
     * the *Needs attention* checks they would see there (Phase 24). A month
     * with nothing due and nothing to check counts as done; one whose digest
     * could not be delivered is retried on the next run.
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
        // The reminders were synced at the start of the run.
        $checks = $this->attention->forVehicles(
            $user,
            $this->vehicles->listByIds($this->access->recipientVehicleIds($user)),
            sync: false,
        )->checks();
        if ($entries === [] && $checks === []) {
            $this->settings->markDigestSent($user->id, $month);

            return false;
        }

        $digest = $this->composer->digest($user, $entries, $today, $checks);
        $report = $this->dispatcher->dispatch($digest, $recipient, $preferences);
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
