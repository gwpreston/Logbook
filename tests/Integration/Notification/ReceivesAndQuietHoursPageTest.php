<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Notification;

use Logbook\Kernel;
use Logbook\Repository\NotificationChannelRepository;
use Logbook\Service\Notification\QuietHours;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Tests\Support\ReminderTestCase;

/**
 * Account → Notifications from Phase 36.4 (spec.md §7.11 *What each
 * channel receives, and quiet hours*): *Receives* on every card, saved with
 * it, at least one, job failures for admins only; *Quiet hours*; tests say
 * they ignore both.
 */
final class ReceivesAndQuietHoursPageTest extends ReminderTestCase
{
    private const array SERVER_ONLY = [
        'APP_URL' => 'https://garage.example',
        'TEST_MAIL_HOST' => 'smtp.test',
        'TEST_MAIL_FROM' => 'Logbook <logbook@garage.example>',
        'TEST_MAIL_TO' => 'owner@example.com',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(Kernel::rootDir() . '/var/cache/rate-limit/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testAPersonalCardSavesWhatItReceives(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);

        $page = self::body($browser->get('/settings/notifications'));
        self::assertStringContainsString('data-receives="ntfy"', $page);
        self::assertStringContainsString('value="job_failures" checked', $page, 'an admin is offered job failures; all ticked');

        $none = $browser->post('/settings/notifications/ntfy', [
            'intent' => 'save',
            'ntfy-url' => 'https://ntfy.test/garage',
            'receives_shown' => '1',
        ]);
        self::assertSame(422, $none->getStatusCode());
        self::assertStringContainsString('Choose at least one, or switch the channel off.', self::body($none));
        self::assertNull($this->service($app, NotificationChannelRepository::class)->find($owner->id, 'ntfy'), 'not saved');

        $saved = $browser->post('/settings/notifications/ntfy', [
            'intent' => 'save',
            'ntfy-url' => 'https://ntfy.test/garage',
            'receives_shown' => '1',
            'receives' => ['overdue', 'price_alerts'],
        ]);
        self::assertSame(303, $saved->getStatusCode());
        $record = $this->service($app, NotificationChannelRepository::class)->find($owner->id, 'ntfy');
        self::assertSame('overdue,price_alerts', $record?->categories);
        $card = self::receivesOf(self::body($browser->follow($saved)), 'ntfy');
        self::assertStringContainsString('value="overdue" checked', $card);
        self::assertStringNotContainsString('value="due" checked', $card);

        // A form without the boxes (Telegram's "use this chat") keeps them.
        $browser->post('/settings/notifications/ntfy', ['intent' => 'save', 'ntfy-url' => 'https://ntfy.test/other']);
        self::assertSame(
            'overdue,price_alerts',
            $this->service($app, NotificationChannelRepository::class)->find($owner->id, 'ntfy')?->categories,
        );
    }

    public function testEmailSavesWhatItReceivesAndAMemberIsNeverOfferedJobFailures(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $this->signedIn($app);
        $sam = $this->withEmail($app, $this->createMember($app, 'sam'), 'sam@example.com');
        $member = $this->browserFor($app, 'sam');

        $page = self::body($member->get('/settings/notifications'));
        self::assertStringContainsString('data-receives="email"', $page);
        self::assertStringNotContainsString('value="job_failures"', $page);

        $saved = $member->post('/settings/notifications/email', [
            'intent' => 'receives',
            'receives_shown' => '1',
            'receives' => ['digest', 'job_failures'],
        ]);
        self::assertSame(303, $saved->getStatusCode());
        $preferences = $this->service($app, ReminderSettingsStore::class)->notificationPreferences($sam->id);
        self::assertSame('digest', $preferences->emailCategories()->toStored(), 'job failures are not a member’s to choose');

        $none = $member->post('/settings/notifications/email', ['intent' => 'receives', 'receives_shown' => '1']);
        self::assertSame(422, $none->getStatusCode());
        self::assertStringContainsString('Choose at least one, or switch the channel off.', self::body($none));
    }

    public function testQuietHoursAreSavedCheckedAndSwitchedOff(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);
        $store = $this->service($app, ReminderSettingsStore::class);

        $page = self::body($browser->get('/settings/notifications'));
        self::assertStringContainsString('Quiet hours', $page);
        self::assertStringContainsString('data-quiet="off"', $page);

        $same = $browser->post('/settings/notifications/quiet', self::quiet('22:00', '22:00'));
        self::assertSame(422, $same->getStatusCode());
        self::assertStringContainsString('The end must differ from the start.', self::body($same));
        $bad = $browser->post('/settings/notifications/quiet', self::quiet('late', '07:00'));
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('Enter a time such as 22:00.', self::body($bad));
        self::assertNull($store->notificationPreferences($owner->id)->quiet);

        $saved = $browser->post('/settings/notifications/quiet', self::quiet('22:00', '07:00'));
        self::assertStringEndsWith('#quiet-hours', $saved->getHeaderLine('Location'));
        self::assertStringContainsString('Quiet hours are 22:00 to 07:00.', self::body($browser->follow($saved)));
        self::assertEquals(QuietHours::of('22:00', '07:00'), $store->notificationPreferences($owner->id)->quiet);

        $browser->post('/settings/notifications/quiet', ['quiet_start' => '22:00', 'quiet_end' => '07:00']);
        self::assertNull($store->notificationPreferences($owner->id)->quiet, 'unticked: off');
    }

    public function testATestIgnoresWhatTheChannelReceivesAndSaysSo(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);
        $store = $this->service($app, ReminderSettingsStore::class);
        $store->saveNotificationPreferences($owner->id, $store->notificationPreferences($owner->id)
            ->withQuiet(QuietHours::of('00:00', '23:59')));
        $browser->get('/settings/notifications');
        $browser->post('/settings/notifications/email', [
            'intent' => 'receives',
            'receives_shown' => '1',
            'receives' => ['digest'],
        ]);

        $tested = self::body($browser->post('/settings/notifications/email', ['intent' => 'test']));

        self::assertCount(1, $this->mail->sent, 'sent in quiet hours, whatever email receives');
        self::assertStringContainsString('Tests are sent whatever the channel receives, even in quiet hours.', $tested);
    }

    /**
     * @return array<string, string>
     */
    private static function quiet(string $start, string $end): array
    {
        return ['quiet_on' => '1', 'quiet_start' => $start, 'quiet_end' => $end];
    }

    private static function receivesOf(string $page, string $kind): string
    {
        self::assertSame(1, preg_match('/<fieldset[^>]*data-receives="' . $kind . '".*?<\/fieldset>/s', $page, $m));

        return $m[0];
    }
}
