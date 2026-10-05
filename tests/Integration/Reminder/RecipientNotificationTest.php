<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Reminder\ReminderPreferences;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\ReminderTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\Mime\Email;

/**
 * Reminders per recipient (spec.md §7.11): a vehicle's reminders go to its
 * owner and to the shares with *Send me its reminders*, each in their own
 * language, through the channels that reach them, once per status; the
 * instance's ntfy topic and MAIL_TO are the admins'.
 */
final class RecipientNotificationTest extends ReminderTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    public function testOwnerAndNotifiedSharesEachGetTheirOwnOnce(): void
    {
        $app = $this->createRecordingApp();
        $clock = $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $partner = $this->member($app, 'partner', 'de_DE', 'Europe/Berlin');
        $viewer = $this->member($app, 'viewer', 'en_US', 'America/New_York');
        $shares = $this->service($app, VehicleShareRepository::class);
        $now = new DateTimeImmutable(self::NOW);
        $shares->insert($golf->id, $partner->id, ShareLevel::Log, false, true, $now);
        $shares->insert($golf->id, $viewer->id, ShareLevel::View, false, false, $now);
        $ntfy = 'https://ntfy.test/sam';
        $partner = $this->withEmail($app, $partner, 'partner@example.com');
        $this->preferences($app, $partner, new NotificationPreferences(null, false, $ntfy));
        $this->withEmail($app, $viewer, 'viewer@example.com');

        $summary = $this->service($app, ScheduledTasks::class)->run();

        self::assertSame(2, $summary->remindersSent, 'the owner and the share that asked');
        $to = $this->recipients();
        self::assertSame(['owner@example.com', 'partner@example.com'], array_keys($to));
        self::assertStringContainsString('Insurance', $to['owner@example.com']);
        self::assertStringContainsString('Versicherung', $to['partner@example.com'], 'in their language');
        self::assertStringContainsString('Läuft in 12 Tagen ab', $to['partner@example.com']);

        $topics = array_map(static fn (array $r): mixed => $r['json']['topic'] ?? null, $this->http->to('https://ntfy.test'));
        sort($topics);
        self::assertSame(['garage', 'sam'], $topics, 'the household topic gets the admin\'s only');
        self::assertSame(['owner', 'partner'], $this->hookUsers(), 'the webhook names each recipient');

        $reminder = $this->onlyReminder($app);
        $deliveries = $this->service($app, ReminderRepository::class)->deliveriesOf($reminder->id);
        self::assertCount(2, $deliveries);

        $this->mail->sent = [];
        $clock->set(new DateTimeImmutable('2026-09-28T10:00:00Z'));
        self::assertSame(0, $this->service($app, ScheduledTasks::class)->run()->remindersSent, 'once per status');
        self::assertSame([], $this->mail->sent);
    }

    public function testARetryAfterOneRecipientFailedNeverDoublesAnother(): void
    {
        // Email and ntfy only: the partner has email alone (no topic of their own).
        $channels = array_diff_key(self::CHANNELS, ['GOTIFY_URL' => 1, 'WEBHOOK_URL' => 1]);
        $app = $this->createRecordingApp($channels);
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $partner = $this->member($app, 'partner', 'en_GB', 'Europe/London');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $partner->id, ShareLevel::View, false, true, new DateTimeImmutable(self::NOW));
        $this->withEmail($app, $partner, 'partner@example.com');

        $this->mail->failing = true;
        $first = $this->service($app, ScheduledTasks::class)->run();
        self::assertSame(1, $first->remindersSent, 'the owner, by ntfy; the partner got nothing');
        self::assertCount(1, $this->http->to('https://ntfy.test'));

        $this->mail->failing = false;
        $second = $this->service($app, ScheduledTasks::class)->run();
        self::assertSame(1, $second->remindersSent, 'the partner, now');
        self::assertSame(['partner@example.com'], array_keys($this->recipients()), 'and not the owner again');
        self::assertCount(1, $this->http->to('https://ntfy.test'), 'the owner\'s ntfy is not repeated');
    }

    public function testAMemberWithoutAnAddressGetsNoMailToTheServerDefault(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $partner = $this->member($app, 'partner', 'en_GB', 'Europe/London');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $partner->id, ShareLevel::View, false, true, new DateTimeImmutable(self::NOW));

        $this->service($app, ScheduledTasks::class)->run();

        self::assertSame(['owner@example.com'], array_keys($this->recipients()), 'MAIL_TO is the admin\'s');
        self::assertSame(['owner', 'partner'], $this->hookUsers(), 'the webhook still reaches them');
    }

    public function testASharedVehicleIsJudgedByItsOwnersLeadTimesWhoeverLooks(): void
    {
        $app = $this->createRecordingApp(self::NO_CHANNELS);
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $partner = $this->member($app, 'partner', 'en_GB', 'Europe/London');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $partner->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));
        // The partner would only call it due in the last 3 days; the owner (30 days) calls it due now.
        $store = $this->service($app, ReminderSettingsStore::class);
        $store->saveReminderPreferences($partner->id, new ReminderPreferences(30, '1000.000', 3, 7));

        $this->browserFor($app, 'partner')->get('/reminders');
        self::assertSame(ReminderStatus::Due, $this->onlyReminder($app)->status, 'the partner looking changes nothing');
        self::assertSame(30, $this->onlyReminder($app)->leadTimeDays);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function member(App $app, string $username, string $locale, string $zone): User
    {
        $preset = str_starts_with($locale, 'en_US') ? UnitPreset::Us : UnitPreset::Metric;

        return $this->createMember($app, $username, new DisplayPreferences(
            $locale,
            $zone,
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'EUR',
        ), displayName: ucfirst($username));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function preferences(App $app, User $user, NotificationPreferences $preferences): void
    {
        $this->service($app, ReminderSettingsStore::class)->saveNotificationPreferences($user->id, $preferences);
    }

    /**
     * @return array<string, string> address => subject and body, sorted by address
     */
    private function recipients(): array
    {
        $to = [];
        foreach ($this->mail->sent as $email) {
            self::assertInstanceOf(Email::class, $email);
            $to[$email->getTo()[0]->getAddress()] = $email->getSubject() . "\n" . $email->getTextBody();
        }
        ksort($to);

        return $to;
    }

    /**
     * The usernames the webhook was sent for, sorted.
     *
     * @return list<string>
     */
    private function hookUsers(): array
    {
        $users = [];
        foreach ($this->http->to('https://hooks.test') as $request) {
            $user = $request['json']['user'] ?? null;
            $users[] = is_array($user) && is_string($user['username'] ?? null) ? $user['username'] : '';
        }
        sort($users);

        return $users;
    }
}
