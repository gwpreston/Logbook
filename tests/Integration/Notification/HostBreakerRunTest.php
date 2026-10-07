<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Notification;

use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Repository\NotificationChannelRepository;
use Logbook\Service\Jobs\Job;
use Logbook\Service\Jobs\JobContext;
use Logbook\Service\Jobs\JobResult;
use Logbook\Service\Jobs\JobRunner;
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

    /**
     * Phase 37 (#267): a host skipped in a run is skipped for the failed-job
     * alert sent after it too, and the breaker is disarmed afterwards.
     */
    public function testTheFailureAlertAfterARunSkipsAHostTheRunFoundDown(): void
    {
        $app = $this->createRecordingApp(['WEBHOOK_URL' => 'https://hooks.test/logbook'] + self::CHANNELS);
        $this->signedIn($app);
        $breaker = $this->service($app, HostBreaker::class);
        $job = new class ($breaker) implements Job {
            public function __construct(private HostBreaker $breaker)
            {
            }

            public function name(): string
            {
                return 'backup';
            }

            public function interval(): ?int
            {
                return null;
            }

            public function run(JobContext $context): JobResult
            {
                for ($i = 0; $i < HostBreaker::AFTER; $i++) {
                    $this->breaker->unanswered('https://hooks.test/logbook');
                }

                return JobResult::failed('The webhook host is down.');
            }
        };
        $runner = $this->service($app, JobRunner::class);

        $runner->run($job, JobTrigger::Manual);
        $second = $runner->run($job, JobTrigger::Manual);

        self::assertSame(JobStatus::Failed, $second->status);
        self::assertCount(1, $this->mail->sent, 'the alert went by email');
        self::assertSame([], $this->http->to('https://hooks.test'), 'and skipped the host the run found down');
        self::assertFalse($breaker->skips('https://hooks.test/logbook'), 'disarmed after the alert');
    }
}
