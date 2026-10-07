<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Notification;

use InvalidArgumentException;
use Logbook\Service\Notification\ChannelCategories;
use Logbook\Service\Notification\ChannelRegistry;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationCategory;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Notification\NotificationDispatcher;
use Logbook\Service\Notification\NotificationKind;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Notification\ReminderMessages;
use Logbook\Tests\Support\CategorisedChannel;
use Logbook\Tests\Support\FakeChannel;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The dispatcher works off the registry only: enabled AND configured
 * channels, whatever their type (spec.md §7.11).
 */
final class NotificationDispatcherTest extends TestCase
{
    public function testOnlyEnabledAndConfiguredChannelsAreUsed(): void
    {
        $email = new FakeChannel('email');
        $ntfy = new FakeChannel('ntfy');
        $gotify = new FakeChannel('gotify', configured: false);
        $webhook = new FakeChannel('webhook');
        $dispatcher = self::dispatcher([$email, $ntfy, $gotify, $webhook]);

        $preferences = new NotificationPreferences(['ntfy', 'gotify', 'webhook']);
        $report = $dispatcher->dispatch(self::notification(), self::recipient(), $preferences);

        self::assertSame(['ntfy', 'webhook'], $report->deliveredChannels());
        self::assertCount(0, $email->sent, 'not enabled');
        self::assertCount(0, $gotify->sent, 'enabled but not configured');
        self::assertCount(1, $ntfy->sent);
        self::assertCount(1, $webhook->sent);
    }

    public function testUntilTheOwnerChoosesEveryConfiguredChannelIsEnabled(): void
    {
        $email = new FakeChannel('email');
        $gotify = new FakeChannel('gotify', configured: false);
        $registry = new ChannelRegistry([$email, $gotify]);

        self::assertSame([$email], $registry->active(new NotificationPreferences(), self::recipient()));
        self::assertSame([], $registry->active(new NotificationPreferences([]), self::recipient()), 'all turned off');
        self::assertSame(['email'], $registry->configuredKeys());
    }

    public function testAFailingChannelNeverStopsTheOthers(): void
    {
        $broken = new FakeChannel('email', behaviour: 'throw');
        $refusing = new FakeChannel('gotify', behaviour: 'fail');
        $working = new FakeChannel('ntfy');

        $report = self::dispatcher([$broken, $refusing, $working])
            ->dispatch(self::notification(), self::recipient(), new NotificationPreferences());

        self::assertTrue($report->anyDelivered());
        self::assertSame(['ntfy'], $report->deliveredChannels());
        self::assertCount(2, $report->failures());
        self::assertSame('connection reset', $report->failures()[0]->error);
    }

    public function testNoChannelsMeansNothingTried(): void
    {
        $report = self::dispatcher([new FakeChannel('email', configured: false)])
            ->dispatch(self::notification(), self::recipient(), new NotificationPreferences());

        self::assertTrue($report->hadNoChannels());
        self::assertFalse($report->anyDelivered());
    }

    /**
     * A new channel is just another implementation in the list: the
     * registry and dispatcher take it without any change.
     */
    public function testANewChannelNeedsNoChangeToTheDispatcher(): void
    {
        $telegram = new FakeChannel('telegram');

        $report = self::dispatcher([new FakeChannel('email', configured: false), $telegram])
            ->dispatch(self::notification(), self::recipient(), new NotificationPreferences(['telegram']));

        self::assertSame(['telegram'], $report->deliveredChannels());
        self::assertSame('Oil change due', $telegram->sent[0]->title);
    }

    /**
     * Phase 36.4: a digest, price alert or failed job goes only to the
     * channels taking it; a test goes everywhere.
     */
    public function testAKindGoesOnlyToTheChannelsTakingIt(): void
    {
        $email = new CategorisedChannel('email', [NotificationCategory::Due]);
        $ntfy = new CategorisedChannel('ntfy', [NotificationCategory::Digest]);
        $webhook = new FakeChannel('webhook');
        $dispatcher = self::dispatcher([$email, $ntfy, $webhook]);

        $digest = new Notification(NotificationKind::Digest, 'Due in October 2026', '…');
        $report = $dispatcher->dispatch($digest, self::recipient(), new NotificationPreferences());
        self::assertSame(['ntfy', 'webhook'], $report->deliveredChannels(), 'the server webhook takes everything');
        self::assertSame(['ntfy', 'webhook'], $report->deliveredChannelsFor(NotificationCategory::Digest));

        $test = new Notification(NotificationKind::Test, 'Logbook test notification', '…');
        $report = $dispatcher->dispatch($test, self::recipient(), new NotificationPreferences());
        self::assertSame(['email', 'ntfy', 'webhook'], $report->deliveredChannels());

        $jobs = new Notification(NotificationKind::JobFailed, 'Failed', '…');
        $report = self::dispatcher([$email, $ntfy])->dispatch($jobs, self::recipient(), new NotificationPreferences());
        self::assertTrue($report->hadNoChannels(), 'nobody takes job failures');
    }

    /**
     * Phase 36.4: a run's reminders go to each channel as the part it
     * takes; the report says which channels delivered each category.
     */
    public function testRemindersGoToEachChannelAsThePartItTakes(): void
    {
        $email = new CategorisedChannel('email', [NotificationCategory::Overdue]);
        $ntfy = new CategorisedChannel('ntfy', [NotificationCategory::Due], 'fail');
        $gotify = new CategorisedChannel('gotify', [NotificationCategory::Due, NotificationCategory::Overdue]);
        $digestOnly = new CategorisedChannel('telegram', [NotificationCategory::Digest]);
        $all = new Notification(NotificationKind::Reminders, 'All', '…');
        $due = new Notification(NotificationKind::Reminders, 'Due', '…');
        $overdue = new Notification(NotificationKind::Reminders, 'Overdue', '…', urgent: true);

        $report = self::dispatcher([$email, $ntfy, $gotify, $digestOnly])->dispatchReminders(
            new ReminderMessages($all, $due, $overdue),
            self::recipient(),
            new NotificationPreferences(),
        );

        self::assertSame(['Overdue'], array_map(static fn (Notification $n): string => $n->title, $email->sent));
        self::assertSame(['Due'], array_map(static fn (Notification $n): string => $n->title, $ntfy->sent));
        self::assertSame(['All'], array_map(static fn (Notification $n): string => $n->title, $gotify->sent));
        self::assertSame([], $digestOnly->sent);
        self::assertSame(['gotify'], $report->deliveredChannelsFor(NotificationCategory::Due), 'ntfy failed');
        self::assertSame(['email', 'gotify'], $report->deliveredChannelsFor(NotificationCategory::Overdue));
    }

    public function testWithOnlyOneKindOfReminderEveryChannelTakingItGetsTheWholeMessage(): void
    {
        $messages = new ReminderMessages(
            $all = new Notification(NotificationKind::Reminders, 'All', '…'),
            $all,
            null,
        );

        self::assertSame([$all, [NotificationCategory::Due]], $messages->for(ChannelCategories::all()));
        self::assertNull($messages->for(ChannelCategories::of([NotificationCategory::Overdue])));
    }

    public function testChannelKeysMustBeUniqueAndSafe(): void
    {
        try {
            new ChannelRegistry([new FakeChannel('email'), new FakeChannel('email')]);
            self::fail('duplicate keys must be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('"email"', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        new ChannelRegistry([new FakeChannel('Not A Key')]);
    }

    /**
     * @param list<NotificationChannel> $channels
     */
    private static function dispatcher(array $channels): NotificationDispatcher
    {
        return new NotificationDispatcher(new ChannelRegistry($channels), new NullLogger());
    }

    private static function notification(): Notification
    {
        return new Notification(NotificationKind::Reminders, 'Oil change due', 'Oil change — Golf: Due in 5 days');
    }

    private static function recipient(): Recipient
    {
        return new Recipient(1, 'Pat Owner');
    }
}
