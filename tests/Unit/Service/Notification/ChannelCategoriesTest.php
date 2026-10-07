<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Notification;

use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Notification\ChannelCategories;
use Logbook\Service\Notification\NotificationCategory;
use Logbook\Service\Notification\NotificationKind;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Notification\QuietHours;
use PHPUnit\Framework\TestCase;

/**
 * What a channel receives (spec.md §7.11, Phase 36.4): all until chosen,
 * stored as a comma list, a member's never holding job failures.
 */
final class ChannelCategoriesTest extends TestCase
{
    public function testAllUntilChosen(): void
    {
        $all = ChannelCategories::fromStored(null);

        self::assertTrue($all->isAll());
        self::assertNull($all->toStored());
        foreach (NotificationCategory::cases() as $category) {
            self::assertTrue($all->takes($category));
        }
    }

    public function testStoredInTheEnumsOrderOnceEach(): void
    {
        $chosen = ChannelCategories::of([NotificationCategory::Digest, NotificationCategory::Due, NotificationCategory::Due]);

        self::assertSame('due,digest', $chosen->toStored());
        self::assertTrue($chosen->takes(NotificationCategory::Due));
        self::assertFalse($chosen->takes(NotificationCategory::Overdue));
        self::assertEquals($chosen, ChannelCategories::fromStored('digest, due,unknown'));
        self::assertTrue(ChannelCategories::fromStored('')->isEmpty());
    }

    public function testAMembersFormNeverHoldsJobFailures(): void
    {
        $posted = ['due', 'job_failures', 'nonsense'];

        self::assertSame('due', ChannelCategories::fromForm($posted, false)->toStored());
        self::assertSame('due,job_failures', ChannelCategories::fromForm($posted, true)->toStored());
        self::assertTrue(ChannelCategories::fromForm(null, true)->isEmpty());
        self::assertSame(['due', 'overdue', 'digest', 'price_alerts'], ChannelCategories::all()->values(false));
    }

    public function testEachKindsCategory(): void
    {
        self::assertSame(NotificationCategory::Digest, NotificationCategory::forKind(NotificationKind::Digest));
        self::assertSame(NotificationCategory::PriceAlerts, NotificationCategory::forKind(NotificationKind::PriceAlert));
        self::assertSame(NotificationCategory::JobFailures, NotificationCategory::forKind(NotificationKind::JobFailed));
        self::assertNull(NotificationCategory::forKind(NotificationKind::Test), 'a test goes everywhere');
        self::assertNull(NotificationCategory::forKind(NotificationKind::ChannelOff), 'so does the switched-off notice');
        self::assertSame(NotificationCategory::Overdue, NotificationCategory::forReminder(ReminderStatus::Overdue));
        self::assertSame(NotificationCategory::Due, NotificationCategory::forReminder(ReminderStatus::Due));
    }

    public function testPreferencesKeepThemThroughEveryChange(): void
    {
        $quiet = QuietHours::of('22:00', '07:00');
        $preferences = (new NotificationPreferences(['email'], true, 'legacy'))
            ->withEmailCategories(ChannelCategories::of([NotificationCategory::Overdue]))
            ->withQuiet($quiet)
            ->withChannel('webhook', false)
            ->withDigest(false)
            ->withoutLegacyGotifyToken();

        self::assertSame('overdue', $preferences->emailCategories()->toStored());
        self::assertEquals($quiet, $preferences->quiet);

        $stored = $preferences->toArray();
        self::assertSame('overdue', $stored['email_categories'] ?? null);
        self::assertSame(['start' => '22:00', 'end' => '07:00'], $stored['quiet'] ?? null);
        self::assertEquals($preferences, NotificationPreferences::fromArray($stored));

        $old = NotificationPreferences::fromArray(['channels' => null, 'digest' => true]);
        self::assertTrue($old->emailCategories()->isAll(), 'a row from before 3.3 takes everything');
        self::assertNull($old->quiet);
        self::assertArrayNotHasKey('email_categories', $old->toArray());
        self::assertArrayNotHasKey('quiet', $old->toArray());
    }
}
