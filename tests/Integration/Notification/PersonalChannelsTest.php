<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Notification;

use DateTimeImmutable;
use Logbook\Domain\Notification\ChannelRecord;
use Logbook\Domain\Notification\MemberDestinations;
use Logbook\Domain\User\User;
use Logbook\Kernel;
use Logbook\Repository\NotificationChannelRepository;
use Logbook\Repository\NotificationSecretRepository;
use Logbook\Service\Mail\NotificationSecrets;
use Logbook\Service\Notification\DispatchReport;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationDispatcher;
use Logbook\Service\Notification\NotificationKind;
use Logbook\Service\Notification\Outbound\OutboundDestination;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Tests\Support\ReminderTestCase;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\Mime\Email;

/**
 * Settings → Account → Notifications and the delivery behind it (spec.md
 * §7.11 *Personal channels*, Phase 36.2): each user's own channels, saved,
 * tested with unsaved values, switched, removed, used per recipient,
 * switched off after five failures in a row, and private to their owner.
 */
final class PersonalChannelsTest extends ReminderTestCase
{
    /** Email and the server's webhook only: the owner starts with no personal channel. */
    private const array SERVER_ONLY = [
        'APP_URL' => 'https://garage.example',
        'TEST_MAIL_HOST' => 'smtp.test',
        'TEST_MAIL_FROM' => 'Logbook <logbook@garage.example>',
        'TEST_MAIL_TO' => 'owner@example.com',
        'WEBHOOK_URL' => 'https://hooks.test/logbook',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // The test throttle counts per user id, which every test reuses.
        foreach (glob(Kernel::rootDir() . '/var/cache/rate-limit/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testAUserSavesTestsSwitchesAndRemovesTheirOwnChannel(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);

        $page = self::body($browser->get('/settings/notifications'));
        self::assertStringContainsString('Always on', $page, 'in the app, always');
        self::assertStringContainsString('data-channel="ntfy"', $page);
        self::assertStringContainsString('data-channel-status="none"', $page);

        $saved = $browser->post('/settings/notifications/ntfy', [
            'intent' => 'save',
            'ntfy-url' => 'https://ntfy.test/garage',
            'ntfy-token' => 'tk_owner_secret',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertStringEndsWith('/settings/notifications#channel-ntfy', $saved->getHeaderLine('Location'));
        $page = self::body($browser->follow($saved));
        self::assertStringContainsString('ntfy is saved.', $page);
        self::assertStringContainsString('data-secret-state="saved"', $page);
        self::assertStringNotContainsString('tk_owner_secret', $page, 'never shown again');
        $record = $this->record($app, $owner, 'ntfy');
        self::assertSame(['url' => 'https://ntfy.test/garage'], $record->values());
        self::assertSame('tk_owner_secret', $this->service($app, NotificationSecrets::class)->open($owner->id, 'ntfy.token'));

        // The test sends what was typed, without saving it.
        $tested = $browser->post('/settings/notifications/ntfy', [
            'intent' => 'test',
            'ntfy-url' => 'https://ntfy.test/other-topic',
        ]);
        self::assertSame(200, $tested->getStatusCode());
        $body = self::body($tested);
        self::assertStringContainsString('Test sent through ntfy. Nothing was saved.', $body);
        self::assertStringContainsString('value="https://ntfy.test/other-topic"', $body, 'what was typed stays on the form');
        $request = $this->http->to('https://ntfy.test')[0];
        self::assertSame('other-topic', $request['json']['topic'] ?? null);
        self::assertSame(['Bearer tk_owner_secret'], $request['headers']['authorization'] ?? null, 'the saved token, same host');
        self::assertSame('https://ntfy.test/garage', $this->record($app, $owner, 'ntfy')->value('url'), 'not saved');
        self::assertNull($this->record($app, $owner, 'ntfy')->lastStatus, 'a test is not the last result');

        $browser->post('/settings/notifications/ntfy/switch', ['enabled' => '0']);
        self::assertFalse($this->record($app, $owner, 'ntfy')->enabled);
        self::assertStringContainsString('data-channel-status="off"', self::body($browser->get('/settings/notifications')));
        $browser->post('/settings/notifications/ntfy/switch', ['enabled' => '1']);
        self::assertTrue($this->record($app, $owner, 'ntfy')->enabled);

        self::assertStringContainsString('Remove ntfy?', self::body($browser->get('/settings/notifications/ntfy/remove')));
        $browser->post('/settings/notifications/ntfy/remove');
        self::assertNull($this->service($app, NotificationChannelRepository::class)->find($owner->id, 'ntfy'));
        self::assertNull($this->service($app, NotificationSecretRepository::class)->find($owner->id, 'ntfy.token'));
    }

    public function testASecretNeverComesBackAndASavedOneOnlyGoesToItsHost(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);
        $browser->post('/settings/notifications/gotify', [
            'intent' => 'save',
            'gotify-url' => 'https://gotify.test',
            'gotify-token' => 'AppTokenSecret',
        ]);

        // A validation error never puts the typed token back.
        $error = $browser->post('/settings/notifications/gotify', [
            'intent' => 'save',
            'gotify-url' => 'gotify.test',
            'gotify-token' => 'TypedTokenSecret',
        ]);
        self::assertSame(422, $error->getStatusCode());
        $body = self::body($error);
        self::assertStringContainsString('Enter a full address starting with https:// or http://.', $body);
        self::assertStringNotContainsString('TypedTokenSecret', $body);
        self::assertStringNotContainsString('AppTokenSecret', $body);

        // Another host: the saved token is not sent there.
        $other = self::body($browser->post('/settings/notifications/gotify', [
            'intent' => 'test',
            'gotify-url' => 'https://gotify2.test',
        ]));
        self::assertStringContainsString('Type the token to test', $other);
        self::assertSame([], $this->http->requests);

        // The same host: it is.
        $browser->post('/settings/notifications/gotify', ['intent' => 'test', 'gotify-url' => 'https://gotify.test/']);
        $sent = $this->http->to('https://gotify.test/message')[0];
        self::assertSame(['AppTokenSecret'], $sent['headers']['x-gotify-key'] ?? null);

        // Another port or plain http is another destination too.
        $browser->post('/settings/notifications/gotify', ['intent' => 'test', 'gotify-url' => 'http://gotify.test']);
        $browser->post('/settings/notifications/gotify', ['intent' => 'test', 'gotify-url' => 'https://gotify.test:8443']);
        self::assertCount(1, $this->http->requests);

        // Saving another host without typing the token drops it and says so.
        $moved = $browser->post('/settings/notifications/gotify', [
            'intent' => 'save',
            'gotify-url' => 'https://gotify2.test',
        ]);
        self::assertStringContainsString('the saved token was not kept', self::body($browser->follow($moved)));
        self::assertNull($this->service($app, NotificationSecretRepository::class)->find($owner->id, 'gotify.token'));
        $page = self::body($browser->get('/settings/notifications'));
        self::assertStringContainsString('data-channel-status="needs_setup"', $page);

        // Nothing typed ever reached the log.
        $log = (string) @file_get_contents(Kernel::rootDir() . '/var/log/testing.log');
        self::assertStringNotContainsString('AppTokenSecret', $log);
        self::assertStringNotContainsString('TypedTokenSecret', $log);
    }

    public function testTheSixthTestInTenMinutesIsRefused(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $browser = $this->signedIn($app);
        $form = ['intent' => 'test', 'personal-webhook-url' => 'https://hooks.test/mine'];

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $browser->post('/settings/notifications/personal-webhook', $form)->getStatusCode());
        }
        $sixth = $browser->post('/settings/notifications/personal-webhook', $form);

        self::assertSame(429, $sixth->getStatusCode());
        self::assertStringContainsString('You have sent 5 tests in the last 10 minutes.', self::body($sixth));
        self::assertCount(5, $this->http->to('https://hooks.test/mine'));
    }

    public function testNobodyElseCanReadChangeTestOrRemoveAChannel(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $this->signedIn($app);
        $owner = $this->owner($app);
        $this->giveChannel($app, $owner, 'ntfy', ['url' => 'https://ntfy.test/owners-topic'], ['token' => 'owners-token']);
        $sam = $this->createMember($app, 'sam');
        $member = $this->browserFor($app, 'sam');

        $page = self::body($member->get('/settings/notifications'));
        self::assertStringNotContainsString('owners-topic', $page);
        self::assertStringContainsString('data-channel-status="none"', $page);

        // Every route is the signed-in user's and the kind's: there is no id to point at the owner's.
        self::assertSame(404, $member->post('/settings/notifications/ntfy/switch', ['enabled' => '0'])->getStatusCode());
        self::assertSame(404, $member->get('/settings/notifications/ntfy/remove')->getStatusCode());
        self::assertSame(404, $member->post('/settings/notifications/ntfy/remove')->getStatusCode());
        self::assertSame(404, $member->post('/settings/notifications/telegram', ['intent' => 'save'])->getStatusCode());
        $member->post('/settings/notifications/ntfy', ['intent' => 'save', 'ntfy-url' => 'https://ntfy.test/sams-topic']);
        $member->post('/settings/notifications/ntfy', ['intent' => 'test', 'ntfy-url' => 'https://ntfy.test/sams-topic']);
        self::assertSame([], array_filter(
            $this->http->requests,
            static fn (array $r): bool => ($r['headers']['authorization'] ?? []) !== [],
        ), 'the owner\'s token is never used for them');

        $mine = $this->record($app, $owner, 'ntfy');
        self::assertTrue($mine->enabled);
        self::assertSame('https://ntfy.test/owners-topic', $mine->value('url'));
        self::assertSame('https://ntfy.test/sams-topic', $this->record($app, $sam, 'ntfy')->value('url'));

        // Admins see nothing of a member's channels, and members can't reach Delivery.
        $adminPage = self::body($this->browserFor($app, 'owner')->get('/settings/notifications'));
        self::assertStringNotContainsString('sams-topic', $adminPage);
        self::assertSame(404, $member->get('/settings/delivery')->getStatusCode());
        $policy = $member->post('/settings/delivery', ['intent' => 'destinations', 'destinations' => 'server']);
        self::assertSame(404, $policy->getStatusCode());
    }

    public function testEveryUsableChannelIsUsedAndOneFailingStopsNoOther(): void
    {
        $app = $this->createRecordingApp();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $this->http->statusFor['https://gotify.test'] = 500;

        $report = $this->dispatch($app, $owner);

        self::assertSame(['email', 'webhook', 'ntfy'], $report->deliveredChannels());
        self::assertCount(1, $this->mail->sent);
        self::assertCount(1, $this->http->to('https://hooks.test'));
        $gotify = $this->record($app, $owner, 'gotify');
        self::assertSame('failed', $gotify->lastStatus);
        self::assertSame('HTTP 500', $gotify->lastError);
        self::assertSame(1, $gotify->failures);
        self::assertSame('ok', $this->record($app, $owner, 'ntfy')->lastStatus);
        self::assertSame('ok', $this->service($app, ReminderSettingsStore::class)->emailResult($owner->id)['status'] ?? null);

        $page = self::body($this->browserFor($app, 'owner')->get('/settings/notifications'));
        self::assertStringContainsString('Last attempt failed', $page);
        self::assertStringContainsString('HTTP 500', $page);
    }

    public function testFiveFailuresInARowSwitchAChannelOffAndTheUserIsToldOnce(): void
    {
        $app = $this->createRecordingApp();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $this->http->statusFor['https://gotify.test'] = 503;

        // Tests never count.
        for ($i = 0; $i < 6; $i++) {
            $this->dispatch($app, $owner, NotificationKind::Test);
        }
        self::assertTrue($this->record($app, $owner, 'gotify')->enabled);
        self::assertSame(0, $this->record($app, $owner, 'gotify')->failures);

        for ($i = 1; $i <= ChannelRecord::SWITCH_OFF_AFTER; $i++) {
            $this->dispatch($app, $owner);
            self::assertSame($i < 5, $this->record($app, $owner, 'gotify')->enabled, 'send ' . $i);
        }
        $off = $this->record($app, $owner, 'gotify');
        self::assertNotNull($off->switchedOffAt);
        self::assertSame(5, $off->failures);

        $title = 'Logbook switched off Gotify';
        $notices = array_values(array_filter(
            $this->http->to('https://ntfy.test'),
            static fn (array $r): bool => ($r['json']['title'] ?? null) === $title,
        ));
        self::assertCount(1, $notices, 'told once, through the channels still on');
        self::assertCount(1, array_filter($this->mail->sent, static fn (Email $e): bool => $e->getSubject() === $title));
        $hooks = array_filter(
            $this->http->to('https://hooks.test'),
            static fn (array $r): bool => ($r['json']['event'] ?? null) === 'channel_off',
        );
        self::assertCount(1, $hooks);

        // Off is off: not tried again, and nobody is told again.
        $before = count($this->http->requests);
        $gotifyBefore = count($this->http->to('https://gotify.test'));
        $this->dispatch($app, $owner);
        self::assertCount($gotifyBefore, $this->http->to('https://gotify.test'));
        self::assertCount($before + 2, $this->http->requests, 'ntfy and the webhook, no new notice');

        $page = self::body($this->browserFor($app, 'owner')->get('/settings/notifications'));
        self::assertStringContainsString('data-channel-status="switched_off"', $page);
        self::assertStringContainsString('Switched off after 5 failed sends in a row.', $page);

        // Switching it on clears the count.
        $this->browserFor($app, 'owner')->post('/settings/notifications/gotify/switch', ['enabled' => '1']);
        $on = $this->record($app, $owner, 'gotify');
        self::assertTrue($on->enabled);
        self::assertNull($on->switchedOffAt);
        self::assertSame(0, $on->failures);
    }

    public function testASuccessResetsTheCountAndEmailIsNeverSwitchedOff(): void
    {
        $app = $this->createRecordingApp();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $this->mail->failing = true;
        $this->http->statusFor['https://ntfy.test'] = 500;

        for ($i = 0; $i < 4; $i++) {
            $this->dispatch($app, $owner);
        }
        self::assertSame(4, $this->record($app, $owner, 'ntfy')->failures);
        unset($this->http->statusFor['https://ntfy.test']);
        $this->dispatch($app, $owner);
        self::assertSame(0, $this->record($app, $owner, 'ntfy')->failures);
        self::assertSame('ok', $this->record($app, $owner, 'ntfy')->lastStatus);

        for ($i = 0; $i < 6; $i++) {
            $this->dispatch($app, $owner);
        }
        $preferences = $this->service($app, ReminderSettingsStore::class)->notificationPreferences($owner->id);
        self::assertTrue($preferences->isEnabled('email'));
        self::assertSame('failed', $this->service($app, ReminderSettingsStore::class)->emailResult($owner->id)['status'] ?? null);
    }

    public function testARefusedDestinationIsShownButNeverSwitchesAChannelOff(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $this->signedIn($app);
        $sam = $this->createMember($app, 'sam');
        // A name that stops resolving (a resolver outage) is refused before any request.
        $this->giveChannel($app, $sam, 'ntfy', ['url' => 'https://gone.test/topic']);

        for ($i = 0; $i < ChannelRecord::SWITCH_OFF_AFTER + 2; $i++) {
            $this->dispatch($app, $sam);
        }

        $record = $this->record($app, $sam, 'ntfy');
        self::assertTrue($record->enabled, 'never switched off for a refusal');
        self::assertSame(0, $record->failures);
        self::assertSame('failed', $record->lastStatus);
        self::assertSame('gone.test could not be found.', $record->lastError);
        self::assertSame([], $this->http->to('https://gone.test'));
    }

    public function testTheServersChannelVariablesAreNoLongerReadAndDeliverySaysSo(): void
    {
        $app = $this->createRecordingApp();
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);
        foreach (['ntfy', 'gotify'] as $kind) {
            $this->service($app, NotificationChannelRepository::class)->delete($owner->id, $kind);
        }

        $this->dispatch($app, $owner);

        self::assertSame([], $this->http->to('https://ntfy.test'), 'NTFY_URL is set, and not read');
        self::assertSame([], $this->http->to('https://gotify.test'));
        self::assertCount(1, $this->http->to('https://hooks.test'), 'WEBHOOK_URL still is');
        $delivery = self::body($browser->get('/settings/delivery'));
        self::assertStringContainsString(
            'NTFY_URL and other NTFY_ or GOTIFY_ variables are set in the environment but are no longer read.',
            $delivery,
        );
        self::assertStringContainsString('WEBHOOK_URL is set', $delivery);
    }

    public function testTheAdminsPolicyDecidesWhereAMembersChannelSends(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $admin = $this->signedIn($app);
        $sam = $this->createMember($app, 'sam');
        $member = $this->browserFor($app, 'sam');
        $this->dns->hosts['ntfy.home'] = ['192.168.1.40'];
        $this->dns->hosts['metadata.example'] = ['169.254.169.254'];

        $admin->get('/settings/delivery');
        $admin->post('/settings/delivery', ['intent' => 'destinations', 'destinations' => 'internet']);
        self::assertSame(MemberDestinations::Internet, $this->service($app, OutboundDestination::class)->policy());

        // Saving an address the policy refuses is refused, with the reason.
        $refused = $member->post('/settings/notifications/ntfy', ['intent' => 'save', 'ntfy-url' => 'http://ntfy.home/garage']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('ntfy.home is an address your administrator does not allow.', self::body($refused));
        $linkLocal = $member->post('/settings/notifications/personal-webhook', [
            'intent' => 'test',
            'personal-webhook-url' => 'http://metadata.example/',
        ]);
        self::assertStringContainsString('link-local or reserved address', self::body($linkLocal));
        self::assertSame([], $this->http->requests);

        // One saved under a wider policy is kept, shown as blocked and not used.
        $admin->post('/settings/delivery', ['intent' => 'destinations', 'destinations' => 'network']);
        $member->post('/settings/notifications/ntfy', ['intent' => 'save', 'ntfy-url' => 'http://ntfy.home/garage']);
        $admin->post('/settings/delivery', ['intent' => 'destinations', 'destinations' => 'internet']);
        self::assertStringContainsString('data-channel-status="blocked"', self::body($member->get('/settings/notifications')));
        $this->dispatch($app, $sam);
        self::assertSame([], $this->http->to('http://ntfy.home'));
        self::assertNotNull($this->service($app, NotificationChannelRepository::class)->find($sam->id, 'ntfy'), 'kept');

        $admin->post('/settings/delivery', ['intent' => 'destinations', 'destinations' => 'network']);
        $this->dispatch($app, $sam);
        self::assertCount(1, $this->http->to('http://ntfy.home'));
        self::assertSame(['ntfy.home' => '192.168.1.40'], $this->http->to('http://ntfy.home')[0]['resolve']);

        // An admin's own channel is not restricted.
        $admin->post('/settings/delivery', ['intent' => 'destinations', 'destinations' => 'internet']);
        $saved = $admin->post('/settings/notifications/ntfy', ['intent' => 'save', 'ntfy-url' => 'http://ntfy.home/admins']);
        self::assertSame(303, $saved->getStatusCode());
    }

    public function testAUsersSecretIsNeverAnEnvReference(): void
    {
        $app = $this->createRecordingApp(self::SERVER_ONLY);
        $this->signedIn($app);
        $owner = $this->owner($app);
        $secrets = $this->service($app, NotificationSecrets::class);

        $secrets->store(null, NotificationSecrets::SMTP_PASSWORD, 'env:SMTP_PASSWORD');
        $repository = $this->service($app, NotificationSecretRepository::class);
        self::assertSame('env:SMTP_PASSWORD', $repository->find(null, 'smtp_password'));

        // Even a reference that got into the table some other way is never read for a user.
        $repository->put($owner->id, 'ntfy.token', 'env:SESSION_SECRET', new DateTimeImmutable());
        self::assertSame(['state' => 'unreadable', 'variable' => null], $secrets->state($owner->id, 'ntfy.token'));
        try {
            $secrets->open($owner->id, 'ntfy.token');
            self::fail('an env: reference was opened for a user');
        } catch (\Logbook\Service\Ai\SecretUnreadable) {
        }

        $this->expectException(InvalidArgumentException::class);
        $secrets->store($owner->id, 'ntfy.token', 'env:SESSION_SECRET');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function dispatch(App $app, User $user, NotificationKind $kind = NotificationKind::Reminders): DispatchReport
    {
        return $this->service($app, NotificationDispatcher::class)->dispatch(
            new Notification($kind, 'Insurance: due soon', 'This needs your attention.', 'https://garage.example/reminders'),
            Recipient::of($user),
            $this->service($app, ReminderSettingsStore::class)->notificationPreferences($user->id),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function record(App $app, User $user, string $kind): ChannelRecord
    {
        $record = $this->service($app, NotificationChannelRepository::class)->find($user->id, $kind);
        self::assertNotNull($record, $kind);

        return $record;
    }
}
