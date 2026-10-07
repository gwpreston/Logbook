<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Notification;

use Logbook\Repository\NotificationChannelRepository;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationDispatcher;
use Logbook\Service\Notification\NotificationKind;
use Logbook\Service\Notification\Outbound\HostBreaker;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Tests\Support\ReminderTestCase;

/**
 * The per-run circuit breaker wired in (spec.md §7.11 *Unreachable
 * services in a run*, #264): a service's requests (Discord's, through
 * OutboundHttp::send) and the server's webhook stop after 3 unanswered
 * ones while a job runs; the skipped ones never count towards switching
 * the channel off.
 */
final class HostBreakerRunTest extends ReminderTestCase
{
    private const string DISCORD_URL = 'https://discord.com/api/webhooks/112233/Discord_Secret-Token';

    public function testAServiceThatStopsAnsweringIsSkippedForTheRestOfTheRun(): void
    {
        $app = $this->createRecordingApp([
            'APP_URL' => 'https://garage.example',
            'WEBHOOK_URL' => 'https://hooks.test/logbook',
        ]);
        $this->dns->hosts['discord.com'] = ['162.159.135.232'];
        $this->signedIn($app);
        $owner = $this->owner($app);
        $this->giveChannel($app, $owner, 'discord', [], ['url' => self::DISCORD_URL]);
        $this->http->errorFor['https://discord.com'] = 'Idle timeout reached';
        $this->http->errorFor['https://hooks.test'] = 'Idle timeout reached';
        $breaker = $this->service($app, HostBreaker::class);
        $dispatcher = $this->service($app, NotificationDispatcher::class);
        $preferences = $this->service($app, ReminderSettingsStore::class)->notificationPreferences($owner->id);
        $send = static fn () => $dispatcher->dispatch(
            new Notification(NotificationKind::Reminders, 'Insurance: due soon', '…'),
            Recipient::of($owner),
            $preferences,
        );

        $breaker->arm();
        try {
            for ($i = 0; $i < 4; $i++) {
                $report = $send();
            }
        } finally {
            $breaker->disarm();
        }

        self::assertCount(3, $this->http->to('https://discord.com'), 'the fourth was skipped');
        self::assertCount(3, $this->http->to('https://hooks.test'), 'so was the server webhook’s');
        $record = $this->service($app, NotificationChannelRepository::class)->find($owner->id, 'discord');
        self::assertSame(3, $record?->failures, 'the skipped one is not counted');
        self::assertSame('notifications.reply.skipped_unreachable', $record->lastError);
        self::assertTrue($report->failures()[0]->refused);

        // Outside a run (a test, a check), it is tried again.
        $send();
        self::assertCount(4, $this->http->to('https://discord.com'));
    }
}
