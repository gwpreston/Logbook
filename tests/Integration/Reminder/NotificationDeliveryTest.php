<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Repository\ReminderRepository;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Service\Scheduler\TaskSummary;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\ReminderTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\Mime\Email;

/**
 * The scheduled task delivering reminders through every shipped channel
 * (email, ntfy, Gotify, webhook) with recorded transports, and never twice
 * (spec.md §7.11).
 */
final class NotificationDeliveryTest extends ReminderTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    public function testEachShippedChannelDelivers(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');

        $summary = $this->runTasks($app);

        self::assertSame(1, $summary->remindersSent);
        $item = 'Insurance — Volkswagen Golf';
        $when = 'Expires in 12 days (9 Oct 2026)';
        $title = $item . ': ' . $when;
        $message = "This needs your attention:\n\n• " . $title;
        $link = 'https://garage.example/reminders';
        $reminder = $this->onlyReminder($app);

        // Email (SMTP).
        self::assertCount(1, $this->mail->sent);
        $email = $this->mail->sent[0];
        self::assertSame($title, $email->getSubject());
        self::assertSame('owner@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('logbook@garage.example', $email->getFrom()[0]->getAddress());
        self::assertSame($message . "\n\n" . $link, $email->getTextBody());

        // ntfy: JSON to the server root, token as a bearer.
        $ntfy = $this->http->to('https://ntfy.test');
        self::assertCount(1, $ntfy);
        self::assertSame('POST', $ntfy[0]['method']);
        self::assertSame('https://ntfy.test/', $ntfy[0]['url']);
        self::assertSame(['Bearer tk_secret'], $ntfy[0]['headers']['authorization']);
        self::assertSame(
            ['topic' => 'garage', 'title' => $title, 'message' => $message, 'priority' => 3, 'tags' => ['car'], 'click' => $link],
            $ntfy[0]['json'],
        );

        // Gotify: /message with the app token and the configured priority.
        $gotify = $this->http->to('https://gotify.test');
        self::assertCount(1, $gotify);
        self::assertSame('https://gotify.test/message', $gotify[0]['url']);
        self::assertSame(['AppToken1'], $gotify[0]['headers']['x-gotify-key']);
        self::assertSame([
            'title' => $title,
            'message' => $message . "\n\n" . $link,
            'priority' => 6,
            'extras' => [
                'client::display' => ['contentType' => 'text/plain'],
                'client::notification' => ['click' => ['url' => $link]],
            ],
        ], $gotify[0]['json']);

        // Webhook: structured JSON.
        $hook = $this->http->to('https://hooks.test');
        self::assertCount(1, $hook);
        self::assertSame([
            'event' => 'reminders',
            'title' => $title,
            'message' => $message,
            'url' => $link,
            'urgent' => false,
            'items' => [[
                'reminder_id' => $reminder->id,
                'title' => $item,
                'detail' => $when,
                'status' => 'due',
                'due_on' => '2026-10-09',
            ]],
            'user' => ['id' => $this->owner($app)->id, 'username' => 'owner', 'display_name' => 'Pat Owner'],
        ], $hook[0]['json']);

        self::assertSame(ReminderStatus::Due, $reminder->notifiedStatus);
        self::assertSame(['email', 'gotify', 'ntfy', 'webhook'], $reminder->channelsNotified);
        self::assertEquals(new DateTimeImmutable(self::NOW), $reminder->lastNotifiedAt);
    }

    /**
     * The idempotency guard: once per status, however often the task runs.
     */
    public function testRepeatedRunsNeverSendTwiceButOverdueIsSentOnce(): void
    {
        $app = $this->createRecordingApp();
        $clock = $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');

        $this->runTasks($app);
        $this->runTasks($app);
        $clock->set(new DateTimeImmutable('2026-10-09T21:00:00Z'));
        $this->runTasks($app);
        self::assertCount(1, $this->mail->sent, 'nothing new: still due (the last day)');

        $clock->set(new DateTimeImmutable('2026-10-10T08:00:00Z'));
        self::assertSame(1, $this->runTasks($app)->remindersSent);
        self::assertSame(0, $this->runTasks($app)->remindersSent);

        self::assertCount(2, $this->mail->sent);
        self::assertSame('Insurance — Volkswagen Golf: Expired yesterday (9 Oct 2026)', $this->mail->sent[1]->getSubject());
        $ntfy = $this->http->to('https://ntfy.test');
        self::assertCount(2, $ntfy);
        self::assertSame(4, $ntfy[1]['json']['priority'] ?? null, 'overdue goes at high priority');
        self::assertSame(8, $this->http->to('https://gotify.test')[1]['json']['priority'] ?? null);
        self::assertSame(ReminderStatus::Overdue, $this->onlyReminder($app)->notifiedStatus);
    }

    public function testSeveralRemindersGoOutAsOneNotification(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $this->document($app, $golf, '2026-09-20', ComplianceType::Inspection, null, 'MOT');
        $this->schedule($app, $golf, 'Annual service', '2025-10-05');

        self::assertSame(3, $this->runTasks($app)->remindersSent);

        self::assertCount(1, $this->mail->sent);
        self::assertSame('3 reminders need attention', $this->mail->sent[0]->getSubject());
        $body = (string) $this->mail->sent[0]->getTextBody();
        self::assertStringContainsString('MOT — Volkswagen Golf: Expired 7 days ago (20 Sept 2026)', $body);
        self::assertStringContainsString('Annual service — Volkswagen Golf: Due in 8 days (5 Oct 2026)', $body);
        $hook = $this->http->to('https://hooks.test')[0]['json'];
        self::assertTrue($hook['urgent'] ?? null, 'one of them is overdue');
        self::assertIsArray($hook['items'] ?? null);
        self::assertCount(3, $hook['items']);
    }

    public function testOnlyTheChannelsTheOwnerEnabledAreUsed(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');

        $form = self::body($browser->get('/settings/reminders'));
        foreach (['email', 'ntfy', 'gotify', 'webhook'] as $key) {
            self::assertStringContainsString('value="' . $key . '" checked', $form, 'every configured channel starts on');
        }

        $browser->post('/settings/reminders', [
            'schedule_days' => '30',
            'schedule_distance' => '621',
            'document_days' => '30',
            'manual_days' => '7',
            'channels' => ['ntfy'],
            'email' => '',
        ]);
        $this->runTasks($app);

        self::assertSame([], $this->mail->sent);
        self::assertCount(1, $this->http->requests);
        self::assertCount(1, $this->http->to('https://ntfy.test'));
        self::assertSame(['ntfy'], $this->onlyReminder($app)->channelsNotified);
    }

    public function testUnconfiguredChannelsAreNeverUsedAndNothingIsClaimed(): void
    {
        $app = $this->createRecordingApp(['APP_URL' => 'https://garage.example', 'WEBHOOK_URL' => 'ftp://not-http.test']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');

        self::assertSame(0, $this->runTasks($app)->remindersSent);
        self::assertSame([], $this->mail->sent);
        self::assertSame([], $this->http->requests);
        self::assertNull($this->onlyReminder($app)->notifiedStatus, 'left for when a channel is set up');

        $form = self::body($browser->get('/settings/reminders'));
        self::assertStringContainsString('value="ntfy" disabled', $form);
        self::assertStringContainsString('No notification channel is turned on and set up yet.', $form);
    }

    public function testWhenNothingIsDeliveredTheNextRunRetries(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');
        $this->mail->failing = true;
        $this->http->status = 503;

        self::assertSame(0, $this->runTasks($app)->remindersSent);
        $reminder = $this->onlyReminder($app);
        self::assertNull($reminder->notifiedStatus, 'the claim was released');
        self::assertNull($reminder->lastNotifiedAt);
        self::assertSame([], $reminder->channelsNotified);

        $this->mail->failing = false;
        $this->http->status = 200;
        self::assertSame(1, $this->runTasks($app)->remindersSent);
        self::assertCount(1, $this->mail->sent);
        self::assertSame(ReminderStatus::Due, $this->onlyReminder($app)->notifiedStatus);
    }

    public function testAPartialFailureIsNotRepeated(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');
        $this->mail->failing = true;

        $this->runTasks($app);
        self::assertSame(['gotify', 'ntfy', 'webhook'], $this->onlyReminder($app)->channelsNotified);

        $this->mail->failing = false;
        $this->runTasks($app);
        self::assertSame([], $this->mail->sent, 'the push channels already delivered; nothing repeats');
        self::assertCount(3, $this->http->requests);
    }

    public function testDismissedUpcomingAndArchivedRemindersAreNotSent(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $bike = $this->vehicle($app, 'Bike');
        $this->document($app, $golf, '2027-03-01');
        $this->document($app, $golf, '2026-10-09', ComplianceType::Inspection);
        $this->document($app, $bike, '2026-10-01');

        $browser->get('/reminders');
        foreach ($this->reminders($app) as $reminder) {
            if ($reminder->vehicleId === $golf->id && $reminder->status === ReminderStatus::Due) {
                $browser->post('/reminders/' . $reminder->id . '/dismiss');
            }
        }
        $this->service($app, VehicleService::class)->archive($this->owner($app), $bike);

        self::assertSame(0, $this->runTasks($app)->remindersSent);
        self::assertSame([], $this->mail->sent);
        self::assertSame([], $this->http->requests);
    }

    public function testOnlyOneOfTwoOverlappingRunsCanClaimAReminder(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');
        $this->service($app, ReminderSync::class)->sync($this->owner($app));
        $reminder = $this->onlyReminder($app);
        $repository = $this->service($app, ReminderRepository::class);

        self::assertTrue($reminder->awaitsNotification());
        $owner = $this->owner($app)->id;
        self::assertTrue($repository->claim($reminder, $owner, new DateTimeImmutable(self::NOW)));
        self::assertFalse($repository->claim($reminder, $owner, new DateTimeImmutable(self::NOW)), 'already taken');
        self::assertSame([], $repository->listAwaitingNotification([$reminder->vehicleId], $owner));
    }

    public function testMonthlyDigestOncePerMonth(): void
    {
        $app = $this->createRecordingApp();
        $clock = $this->pinClock($app, '2026-10-01T07:00:00Z');
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-25');
        $this->schedule($app, $golf, 'Annual service', '2025-12-15');
        $this->runTasks($app);
        self::assertSame([], $this->digests(), 'off by default');

        $browser->post('/settings/reminders', [
            'schedule_days' => '30',
            'schedule_distance' => '621',
            'document_days' => '30',
            'manual_days' => '7',
            'channels' => ['email'],
            'email' => 'pat@example.com',
            'digest' => '1',
        ]);
        $summary = $this->runTasks($app);
        self::assertSame(1, $summary->digestsSent);
        $this->runTasks($app);

        $digests = $this->digests();
        self::assertCount(1, $digests, 'once a month');
        self::assertSame('Due in October 2026', $digests[0]->getSubject());
        self::assertSame('pat@example.com', $digests[0]->getTo()[0]->getAddress(), 'the owner’s own address');
        $body = (string) $digests[0]->getTextBody();
        self::assertStringContainsString('One thing is due by the end of October 2026:', $body);
        self::assertStringContainsString('Insurance — Volkswagen Golf', $body);
        self::assertStringNotContainsString('Annual service', $body, 'due in December');

        $clock->set(new DateTimeImmutable('2026-12-01T07:00:00Z'));
        $this->runTasks($app);
        self::assertCount(2, $this->digests());
        self::assertSame('Due in December 2026', $this->digests()[1]->getSubject());
        $december = (string) $this->digests()[1]->getTextBody();
        self::assertStringContainsString('Insurance — Volkswagen Golf: Expired', $december, 'overdue included');
    }

    public function testTestNotificationFromSettings(): void
    {
        $app = $this->createRecordingApp();
        $browser = $this->signedIn($app);
        $browser->get('/settings/reminders');
        $this->http->status = 500;

        $response = $browser->post('/settings/reminders/test');
        self::assertSame('/settings/reminders', $response->getHeaderLine('Location'));
        $html = self::body($browser->follow($response));
        self::assertStringContainsString('The test was sent via email.', $html);
        self::assertStringContainsString('The test could not be sent via ntfy', $html);
        self::assertSame('Logbook test notification', $this->mail->sent[0]->getSubject());
        self::assertStringContainsString('Hello Pat Owner', (string) $this->mail->sent[0]->getTextBody());
    }

    public function testTheRunnerScriptRunsAndHonoursTheLock(): void
    {
        $root = dirname(__DIR__, 3);
        $command = [PHP_BINARY, $root . '/bin/run-scheduled-tasks.php', '-v'];

        [$code, $output] = self::execute($command);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('account(s) checked', $output);

        // The first run above created var/cache.
        $lock = fopen($root . '/var/cache/scheduled-tasks.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        try {
            [$code, $output] = self::execute($command);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertSame(2, $code, 'another run holds the lock');
        self::assertStringContainsString('in progress', $output);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function runTasks(App $app): TaskSummary
    {
        return $this->service($app, ScheduledTasks::class)->run();
    }

    /**
     * @return list<Email>
     */
    private function digests(): array
    {
        return array_values(array_filter(
            $this->mail->sent,
            static fn (Email $e): bool => str_starts_with((string) $e->getSubject(), 'Due in'),
        ));
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string}
     */
    private static function execute(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }
}
