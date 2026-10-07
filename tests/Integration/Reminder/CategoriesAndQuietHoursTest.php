<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Repository\NotificationChannelRepository;
use Logbook\Service\Notification\ChannelCategories;
use Logbook\Service\Notification\NotificationCategory;
use Logbook\Service\Notification\QuietHours;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Service\Scheduler\TaskSummary;
use Logbook\Tests\Support\ReminderTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 36.4 (spec.md §7.11 *What each channel receives, and quiet
 * hours*): each channel gets the reminders it takes, as one message; a
 * reminder is claimed only while a channel takes it, and recorded against
 * the channels that delivered it; inside quiet hours nothing is claimed,
 * and the first run after sends what still applies then.
 */
final class CategoriesAndQuietHoursTest extends ReminderTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';
    /** Email and ntfy only: no Gotify, no server webhook (it takes everything). */
    private const array TWO_CHANNELS = [
        'APP_URL' => 'https://garage.example',
        'TEST_MAIL_HOST' => 'smtp.test',
        'TEST_MAIL_FROM' => 'Logbook <logbook@garage.example>',
        'TEST_MAIL_TO' => 'owner@example.com',
        'NTFY_URL' => 'https://ntfy.test/garage',
        'NTFY_TOKEN' => 'tk_secret',
    ];
    private const string INSURANCE = 'Insurance — Volkswagen Golf: Expires in 12 days (9 Oct 2026)';
    private const string MOT = 'MOT — Volkswagen Golf: Expired 7 days ago (20 Sept 2026)';

    public function testEachChannelGetsOnlyWhatItTakes(): void
    {
        $app = $this->twoReminders();
        $this->receives($app, email: [NotificationCategory::Overdue], ntfy: [NotificationCategory::Due]);

        self::assertSame(2, $this->runTasks($app)->remindersSent);

        self::assertCount(1, $this->mail->sent);
        self::assertSame(self::MOT, $this->mail->sent[0]->getSubject(), 'email: the overdue one only');
        $ntfy = $this->http->to('https://ntfy.test');
        self::assertCount(1, $ntfy);
        self::assertSame(self::INSURANCE, $ntfy[0]['json']['title'] ?? null, 'ntfy: the due one only');
        self::assertSame(3, $ntfy[0]['json']['priority'] ?? null, 'normal: nothing overdue in it');

        self::assertSame(['ntfy'], $this->reminder($app, 'Insurance')->channelsNotified);
        self::assertSame(['email'], $this->reminder($app, 'MOT')->channelsNotified);
    }

    public function testAChannelTakingBothGetsTheMessageItAlwaysGot(): void
    {
        $app = $this->twoReminders();
        $both = [NotificationCategory::Due, NotificationCategory::Overdue];
        $this->receives($app, email: $both, ntfy: [NotificationCategory::Due]);

        $this->runTasks($app);

        self::assertSame('2 reminders need attention', $this->mail->sent[0]->getSubject());
        self::assertSame(['email', 'ntfy'], $this->reminder($app, 'Insurance')->channelsNotified);
        self::assertSame(['email'], $this->reminder($app, 'MOT')->channelsNotified);
    }

    public function testOnlyTheGroupThatFailedIsRetried(): void
    {
        $app = $this->twoReminders();
        $this->receives($app, email: [NotificationCategory::Overdue], ntfy: [NotificationCategory::Due]);
        $this->mail->failing = true;

        self::assertSame(1, $this->runTasks($app)->remindersSent);
        self::assertNull($this->reminder($app, 'MOT')->notifiedStatus, 'released: its only channel failed');
        self::assertSame(ReminderStatus::Due, $this->reminder($app, 'Insurance')->notifiedStatus);

        $this->mail->failing = false;
        self::assertSame(1, $this->runTasks($app)->remindersSent);
        self::assertSame(self::MOT, $this->mail->sent[0]->getSubject());
        self::assertCount(1, $this->http->to('https://ntfy.test'), 'ntfy is not sent the due one again');
    }

    public function testAReminderNoChannelTakesIsLeftUnclaimed(): void
    {
        $app = $this->twoReminders();
        $this->receives($app, email: [NotificationCategory::Due], ntfy: [NotificationCategory::Due]);

        self::assertSame(1, $this->runTasks($app)->remindersSent);
        self::assertNull($this->reminder($app, 'MOT')->notifiedStatus, 'unclaimed, as with no channel');
        self::assertStringNotContainsString('MOT', (string) $this->mail->sent[0]->getSubject());

        // Once a channel takes overdue reminders, it is sent.
        $this->receives($app, email: [NotificationCategory::Overdue], ntfy: [NotificationCategory::Due]);
        self::assertSame(1, $this->runTasks($app)->remindersSent);
        self::assertSame(self::MOT, $this->mail->sent[1]->getSubject());
    }

    public function testNothingIsSentInQuietHoursAndWhatStillAppliesGoesAfter(): void
    {
        $app = $this->twoReminders();
        $clock = $this->pinClock($app, self::NOW);
        // 09:30 to 12:30 holds 10:00 UTC whether the owner is on UTC or British time.
        $this->quiet($app, '09:30', '12:30');

        self::assertSame(0, $this->runTasks($app)->remindersSent);
        self::assertSame([], $this->mail->sent);
        self::assertSame([], $this->http->requests);
        self::assertNull($this->reminder($app, 'MOT')->notifiedStatus, 'nothing claimed');

        // Done meanwhile: not sent (#252).
        $this->service($app, ReminderService::class)->markDone($this->reminder($app, 'MOT'));

        $clock->set(new DateTimeImmutable('2026-09-27T13:00:00Z'));
        self::assertSame(1, $this->runTasks($app)->remindersSent);
        self::assertCount(1, $this->mail->sent, 'one message (#253)');
        self::assertSame(self::INSURANCE, $this->mail->sent[0]->getSubject());
        self::assertSame(0, $this->runTasks($app)->remindersSent, 'once');
    }

    public function testQuietHoursCanRunPastMidnight(): void
    {
        $app = $this->twoReminders();
        $clock = $this->pinClock($app, '2026-09-27T23:30:00Z');
        $this->quiet($app, '22:00', '07:00');

        self::assertSame(0, $this->runTasks($app)->remindersSent);
        $clock->set(new DateTimeImmutable('2026-09-28T03:00:00Z'));
        self::assertSame(0, $this->runTasks($app)->remindersSent);

        $clock->set(new DateTimeImmutable('2026-09-28T08:00:00Z'));
        self::assertSame(2, $this->runTasks($app)->remindersSent);
    }

    public function testTheDigestWaitsForQuietHoursAndForAChannelTakingIt(): void
    {
        $app = $this->createRecordingApp(self::TWO_CHANNELS);
        $clock = $this->pinClock($app, '2026-10-01T10:00:00Z');
        $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-25');
        $owner = $this->owner($app);
        $store = $this->service($app, ReminderSettingsStore::class);
        $store->saveNotificationPreferences($owner->id, $store->notificationPreferences($owner->id)
            ->withDigest(true)
            ->withEmailCategories(ChannelCategories::of([NotificationCategory::Due])));
        $this->setNtfy($app, [NotificationCategory::Due]);

        self::assertSame(0, $this->runTasks($app)->digestsSent, 'no channel takes the digest');
        self::assertNull($store->digestMonth($owner->id), 'not marked done');

        $store->saveNotificationPreferences($owner->id, $store->notificationPreferences($owner->id)
            ->withEmailCategories(ChannelCategories::all())
            ->withQuiet(QuietHours::of('09:30', '12:30')));
        self::assertSame(0, $this->runTasks($app)->digestsSent, 'held in quiet hours');

        $clock->set(new DateTimeImmutable('2026-10-01T13:00:00Z'));
        self::assertSame(1, $this->runTasks($app)->digestsSent);
        $digests = array_values(array_filter(
            $this->mail->sent,
            static fn ($e): bool => str_starts_with((string) $e->getSubject(), 'Due in'),
        ));
        self::assertCount(1, $digests, 'by email, the only channel taking it');
        self::assertSame([], array_filter(
            $this->http->to('https://ntfy.test'),
            static fn (array $r): bool => str_starts_with((string) ($r['json']['title'] ?? ''), 'Due in'),
        ));
    }

    public function testUpgradingChangesNothing(): void
    {
        $app = $this->twoReminders();

        $this->runTasks($app);

        self::assertSame('2 reminders need attention', $this->mail->sent[0]->getSubject());
        self::assertSame('2 reminders need attention', $this->http->to('https://ntfy.test')[0]['json']['title'] ?? null);
    }

    /**
     * The owner with email and ntfy, and an insurance due in 12 days and an
     * MOT 7 days overdue.
     *
     * @return App<ContainerInterface>
     */
    private function twoReminders(): App
    {
        $app = $this->createRecordingApp(self::TWO_CHANNELS);
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->ownerFromBefore21($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $this->document($app, $golf, '2026-09-20', ComplianceType::Inspection, null, 'MOT');

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<NotificationCategory> $email
     * @param list<NotificationCategory> $ntfy
     */
    private function receives(App $app, array $email, array $ntfy): void
    {
        $owner = $this->owner($app);
        $store = $this->service($app, ReminderSettingsStore::class);
        $store->saveNotificationPreferences(
            $owner->id,
            $store->notificationPreferences($owner->id)->withEmailCategories(ChannelCategories::of($email)),
        );
        $this->setNtfy($app, $ntfy);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<NotificationCategory> $categories
     */
    private function setNtfy(App $app, array $categories): void
    {
        $stored = ChannelCategories::of($categories)->toStored();
        $this->service($app, NotificationChannelRepository::class)
            ->setCategories($this->owner($app)->id, 'ntfy', $stored, new DateTimeImmutable());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function quiet(App $app, string $start, string $end): void
    {
        $owner = $this->owner($app);
        $store = $this->service($app, ReminderSettingsStore::class);
        $preferences = $store->notificationPreferences($owner->id)->withQuiet(QuietHours::of($start, $end));
        $store->saveNotificationPreferences($owner->id, $preferences);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function reminder(App $app, string $which): Reminder
    {
        $due = ['Insurance' => '2026-10-09', 'MOT' => '2026-09-20'][$which];
        foreach ($this->reminders($app) as $reminder) {
            if ($reminder->dueOn?->format('Y-m-d') === $due) {
                return $reminder;
            }
        }
        self::fail('No reminder ' . $which);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function runTasks(App $app): TaskSummary
    {
        return $this->service($app, ScheduledTasks::class)->run();
    }
}
