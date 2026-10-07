<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Notification;

use Logbook\Action\Settings\Notifications\ChannelAction;
use Logbook\Domain\Notification\ChannelRecord;
use Logbook\Domain\Notification\MemberDestinations;
use Logbook\Domain\User\User;
use Logbook\Kernel;
use Logbook\Repository\NotificationChannelRepository;
use Logbook\Service\Mail\NotificationSecrets;
use Logbook\Service\Notification\DispatchReport;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationDispatcher;
use Logbook\Service\Notification\NotificationItem;
use Logbook\Service\Notification\NotificationKind;
use Logbook\Service\Notification\Outbound\OutboundDestination;
use Logbook\Service\Notification\Personal\ChannelSettings;
use Logbook\Service\Notification\Personal\DiscordSender;
use Logbook\Service\Notification\Personal\MattermostSender;
use Logbook\Service\Notification\Personal\PersonalSender;
use Logbook\Service\Notification\Personal\PushoverSender;
use Logbook\Service\Notification\Personal\SlackSender;
use Logbook\Service\Notification\Personal\TelegramSender;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Tests\Support\ReminderTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Telegram, Discord, Pushover, Mattermost and Slack (spec.md §7.11, Phase
 * 36.3): the exact request each sends, the check on saving, *Find my
 * chat*, the destination policy, all nine kinds at once, saved settings
 * re-checked on every send, and no token anywhere it should not be.
 */
final class ServiceChannelsTest extends ReminderTestCase
{
    private const array ENV = [
        'APP_URL' => 'https://garage.example',
        'TEST_MAIL_HOST' => 'smtp.test',
        'TEST_MAIL_FROM' => 'Logbook <logbook@garage.example>',
        'TEST_MAIL_TO' => 'owner@example.com',
    ];
    private const string TELEGRAM_TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';
    private const string DISCORD_URL = 'https://discord.com/api/webhooks/112233/Discord_Secret-Token';
    private const string PUSHOVER_TOKEN = 'azGDORePK8gMaC0QOYAMyEEuzJnyUi';
    private const string PUSHOVER_USER = 'uQiRzpo4DXghDmr9QzzfQu27cmVRsG';
    private const string MATTERMOST_URL = 'https://chat.example.com/hooks/mmsecrethook123';
    private const string SLACK_TOKEN = 'xoxb-1111-2222-SlackSecretToken';
    private const string LINK = 'https://garage.example/reminders';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(Kernel::rootDir() . '/var/cache/rate-limit/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testTelegramSendsPlainTextWithoutPreviewsAndTheDigestQuietly(): void
    {
        $app = $this->app();
        $settings = new ChannelSettings(['chat_id' => '42'], ['token' => self::TELEGRAM_TOKEN]);
        $sender = $this->sender($app, TelegramSender::class);

        foreach ([self::reminder(true), self::digest(), self::priceAlert(), self::test()] as $notification) {
            self::assertTrue($sender->send($notification, $this->recipient($app), $settings, false)->delivered);
        }

        [$reminder, $digest, $price, $test] = $this->http->requests;
        self::assertSame('POST', $reminder['method']);
        self::assertSame('https://api.telegram.org/bot' . self::TELEGRAM_TOKEN . '/sendMessage', $reminder['url']);
        self::assertSame([
            'chat_id' => '42',
            'text' => "MOT: overdue\n\nThese need your attention:\n\n• MOT — @everyone *Golf*: overdue\n\n" . self::LINK,
            'link_preview_options' => ['is_disabled' => true],
        ], $reminder['json'], 'no parse_mode: plain text');
        self::assertTrue($digest['json']['disable_notification'] ?? false, 'the digest arrives without a sound');
        self::assertArrayNotHasKey('disable_notification', $price['json']);
        $text = $test['json']['text'] ?? null;
        self::assertIsString($text);
        self::assertStringStartsWith('Logbook test notification', $text);
    }

    public function testDiscordPingsNobodyAndSuppressesPreviews(): void
    {
        $app = $this->app();
        $this->http->status = 204;

        $result = $this->sender($app, DiscordSender::class)
            ->send(self::reminder(false), $this->recipient($app), new ChannelSettings([], ['url' => self::DISCORD_URL]), false);

        self::assertTrue($result->delivered);
        $request = $this->http->requests[0];
        self::assertSame(self::DISCORD_URL, $request['url']);
        self::assertSame([
            'content' => "MOT: due\n\nThese need your attention:\n\n• MOT — @everyone *Golf*: due\n\n" . self::LINK,
            'username' => 'Logbook',
            'allowed_mentions' => ['parse' => []],
            'flags' => 4,
        ], $request['json']);
    }

    public function testPushoverSendsAFormWithPriorityAndTheLink(): void
    {
        $app = $this->app();
        $this->http->bodyFor['https://api.pushover.net'] = '{"status":1,"request":"r"}';
        $sender = $this->sender($app, PushoverSender::class);
        $settings = new ChannelSettings(['device' => 'pixel'], ['token' => self::PUSHOVER_TOKEN, 'user' => self::PUSHOVER_USER]);

        $all = [self::reminder(true), self::reminder(false), self::digest(), self::priceAlert(), self::test()];
        foreach ($all as $notification) {
            self::assertTrue($sender->send($notification, $this->recipient($app), $settings, false)->delivered);
        }

        $forms = array_column($this->http->requests, 'form');
        self::assertSame('https://api.pushover.net/1/messages.json', $this->http->requests[0]['url']);
        self::assertSame([
            'token' => self::PUSHOVER_TOKEN,
            'user' => self::PUSHOVER_USER,
            'title' => 'MOT: overdue',
            'message' => "These need your attention:\n\n• MOT — @everyone *Golf*: overdue",
            'priority' => '1',
            'url' => self::LINK,
            'url_title' => 'Open Logbook',
            'device' => 'pixel',
        ], $forms[0]);
        // Overdue, due, the digest, a price alert, a test; never 2.
        self::assertSame(['1', '0', '-1', '0', '0'], array_column($forms, 'priority'));
    }

    public function testMattermostEscapesWhatUsersTypedAndUsesTheChosenChannel(): void
    {
        $app = $this->app();

        $result = $this->sender($app, MattermostSender::class)->send(
            self::reminder(false),
            $this->recipient($app),
            new ChannelSettings(['channel' => 'town-square'], ['url' => self::MATTERMOST_URL]),
            false,
        );

        self::assertTrue($result->delivered);
        $z = MattermostSender::ZWSP;
        self::assertSame([
            'text' => "**MOT: due**\n\nThese need your attention:\n\n- MOT — @{$z}everyone \\*Golf\\*: due\n\n" . self::LINK,
            'channel' => 'town-square',
        ], $this->http->requests[0]['json']);
    }

    public function testSlackSendsEscapedPlainTextWithTheBotToken(): void
    {
        $app = $this->app();
        $this->http->bodyFor['https://slack.com'] = '{"ok":true}';

        $notification = new Notification(NotificationKind::Reminders, 'Tax <!channel>', '• Tax — <@U123> & co: due', self::LINK);
        $settings = new ChannelSettings(['channel' => 'C0123456789'], ['token' => self::SLACK_TOKEN]);
        $result = $this->sender($app, SlackSender::class)->send($notification, $this->recipient($app), $settings, false);

        self::assertTrue($result->delivered);
        $request = $this->http->requests[0];
        self::assertSame('https://slack.com/api/chat.postMessage', $request['url']);
        self::assertSame(['Bearer ' . self::SLACK_TOKEN], $request['headers']['authorization'] ?? null);
        self::assertSame([
            'channel' => 'C0123456789',
            'text' => "Tax &lt;!channel&gt;\n\n• Tax — &lt;@U123&gt; &amp; co: due\n\n" . self::LINK,
            'mrkdwn' => false,
            'unfurl_links' => false,
            'unfurl_media' => false,
        ], $request['json']);
        self::assertArrayNotHasKey('link_names', $request['json']);

        // Slack says no with a 200.
        $this->http->bodyFor['https://slack.com'] = '{"ok":false,"error":"not_in_channel"}';
        $failed = $this->sender($app, SlackSender::class)->send($notification, $this->recipient($app), $settings, false);
        self::assertFalse($failed->delivered);
        self::assertSame('notifications.reply.slack_not_in_channel', $failed->error);
    }

    public function testALongDigestIsCutAtALineWithTheCountAndTheLink(): void
    {
        $app = $this->app();
        $lines = ['These need your attention:', ''];
        for ($i = 1; $i <= 200; $i++) {
            $lines[] = sprintf('• Service %03d — a vehicle with a long name 🚗: due in %d days', $i, $i);
        }
        $digest = new Notification(NotificationKind::Digest, 'Due in October 2026', implode("\n", $lines), self::LINK);

        $this->sender($app, DiscordSender::class)
            ->send($digest, $this->recipient($app), new ChannelSettings([], ['url' => self::DISCORD_URL]), false);

        $content = $this->http->requests[0]['json']['content'] ?? null;
        self::assertIsString($content);
        self::assertLessThanOrEqual(DiscordSender::LIMIT, mb_strlen($content));
        self::assertMatchesRegularExpression('/\n…and \d+ more\n\n' . preg_quote(self::LINK, '/') . '$/u', $content);
    }

    public function testTelegramIsCheckedOnSavingAndRejectedTokensAreNotSaved(): void
    {
        $app = $this->app();
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);

        // Rejected: not saved, and the field says why.
        $this->http->statusFor['https://api.telegram.org'] = 401;
        $typed = ['intent' => 'save', 'telegram-token' => self::TELEGRAM_TOKEN];
        $rejected = $browser->post('/settings/notifications/telegram', $typed);
        self::assertSame(422, $rejected->getStatusCode());
        self::assertStringContainsString('Telegram rejected the bot token.', self::body($rejected));
        self::assertNull($this->service($app, NotificationChannelRepository::class)->find($owner->id, 'telegram'));

        // Unreachable: saved, with a notice.
        $this->http->statusFor['https://api.telegram.org'] = 502;
        $saved = $browser->post('/settings/notifications/telegram', $typed);
        self::assertSame(303, $saved->getStatusCode());
        $page = self::body($browser->follow($saved));
        self::assertStringContainsString('Saved, but Telegram couldn’t be reached to check it.', $page);
        self::assertStringContainsString('data-channel-status="needs_setup"', $page, 'no chat yet');
        self::assertStringContainsString('This sends your reminders through Telegram’s servers.', $page);
        self::assertStringNotContainsString(self::TELEGRAM_TOKEN, $page);

        // Works: says the bot's name.
        unset($this->http->statusFor['https://api.telegram.org']);
        $this->http->bodyFor['https://api.telegram.org'] = '{"ok":true,"result":{"id":1,"is_bot":true,"username":"garage_bot"}}';
        $named = $browser->post('/settings/notifications/telegram', ['intent' => 'save', 'telegram-chat_id' => '42']);
        self::assertStringContainsString('Telegram is saved: @garage_bot.', self::body($browser->follow($named)));
        self::assertSame('42', $this->record($app, $owner, 'telegram')->value('chat_id'));
        $last = $this->http->requests[count($this->http->requests) - 1];
        self::assertStringEndsWith('/getMe', $last['url'], 'the saved token, checked again');
    }

    public function testPushoverAndSlackAreCheckedOnSaving(): void
    {
        $app = $this->app();
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);

        $this->http->statusFor['https://api.pushover.net'] = 400;
        $this->http->bodyFor['https://api.pushover.net'] = '{"status":0,"user":"invalid","errors":["user key is invalid"]}';
        $rejected = $browser->post('/settings/notifications/pushover', [
            'intent' => 'save',
            'pushover-token' => self::PUSHOVER_TOKEN,
            'pushover-user' => self::PUSHOVER_USER,
        ]);
        self::assertSame(422, $rejected->getStatusCode());
        self::assertStringContainsString('Pushover rejected the token or the user key.', self::body($rejected));
        self::assertSame('https://api.pushover.net/1/users/validate.json', $this->http->requests[0]['url']);

        unset($this->http->statusFor['https://api.pushover.net']);
        $this->http->bodyFor['https://api.pushover.net'] = '{"status":1,"devices":["pixel"]}';
        $browser->post('/settings/notifications/pushover', [
            'intent' => 'save',
            'pushover-token' => self::PUSHOVER_TOKEN,
            'pushover-user' => self::PUSHOVER_USER,
        ]);
        self::assertTrue($this->record($app, $owner, 'pushover')->enabled);

        $this->http->bodyFor['https://slack.com'] = '{"ok":false,"error":"invalid_auth"}';
        $typed = ['intent' => 'save', 'slack-token' => self::SLACK_TOKEN, 'slack-channel' => '#garage'];
        $slack = $browser->post('/settings/notifications/slack', $typed);
        self::assertSame(422, $slack->getStatusCode());
        self::assertStringContainsString('Slack rejected the bot token.', self::body($slack));

        $this->http->bodyFor['https://slack.com'] = '{"ok":true,"team":"Home Garage"}';
        $saved = $browser->post('/settings/notifications/slack', $typed);
        self::assertStringContainsString('Slack is saved: Home Garage.', self::body($browser->follow($saved)));
    }

    public function testTheCheckOnSavingIsLimitedAndThenSavesUnchecked(): void
    {
        $app = $this->app();
        $browser = $this->signedIn($app);
        $this->http->bodyFor['https://slack.com'] = '{"ok":true,"team":"Home Garage"}';
        $typed = ['intent' => 'save', 'slack-token' => self::SLACK_TOKEN, 'slack-channel' => '#garage'];

        for ($i = 0; $i < ChannelAction::CHECK_MAX; $i++) {
            $browser->post('/settings/notifications/slack', $typed);
        }
        self::assertCount(ChannelAction::CHECK_MAX, $this->http->to('https://slack.com/api/auth.test'));

        $saved = $browser->post('/settings/notifications/slack', $typed);
        self::assertSame(303, $saved->getStatusCode());
        self::assertStringContainsString('couldn’t be reached to check it', self::body($browser->follow($saved)));
        self::assertCount(ChannelAction::CHECK_MAX, $this->http->to('https://slack.com/api/auth.test'), 'not asked again');
    }

    public function testFindMyChatListsPrivateChatsAndKeepsOnlyThePickedId(): void
    {
        $app = $this->app();
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);

        $page = self::body($browser->get('/settings/notifications'));
        self::assertStringContainsString('Save the bot token first; then Find my chat', $page);
        self::assertSame(422, $browser->post('/settings/notifications/telegram/chats')->getStatusCode(), 'no token saved yet');

        $this->giveChannel($app, $owner, 'telegram', [], ['token' => self::TELEGRAM_TOKEN]);
        $this->http->bodyFor['https://api.telegram.org'] = (string) json_encode(['ok' => true, 'result' => [
            ['update_id' => 1, 'message' => ['chat' => [
                'id' => 111,
                'type' => 'private',
                'first_name' => 'Gareth',
                'last_name' => 'Kept-Out',
            ]]],
            ['update_id' => 2, 'message' => ['chat' => ['id' => -100200, 'type' => 'supergroup', 'title' => 'A group']]],
            ['update_id' => 3, 'edited_message' => ['chat' => ['id' => 222, 'type' => 'private', 'first_name' => '<b>Sam</b>']]],
            ['update_id' => 4, 'message' => ['chat' => ['id' => 111, 'type' => 'private', 'first_name' => 'Gareth']]],
        ]]);

        $found = $browser->post('/settings/notifications/telegram/chats');
        self::assertSame(200, $found->getStatusCode());
        $body = self::body($found);
        self::assertStringEndsWith('/getUpdates', $this->http->requests[0]['url']);
        self::assertArrayNotHasKey('offset', $this->http->requests[0]['json'], 'nothing is consumed');
        self::assertStringContainsString('value="111"', $body);
        self::assertStringContainsString('value="222"', $body);
        self::assertStringContainsString('&lt;b&gt;Sam&lt;/b&gt;', $body, 'escaped');
        self::assertStringNotContainsString('-100200', $body, 'private chats only');
        self::assertStringNotContainsString('Kept-Out', $body);
        self::assertSame(1, substr_count($body, 'value="111"'), 'each chat once');
        self::assertStringNotContainsString(self::TELEGRAM_TOKEN, $body);

        $this->http->bodyFor['https://api.telegram.org'] = '{"ok":true,"result":{"username":"garage_bot"}}';
        $browser->post('/settings/notifications/telegram', ['intent' => 'save', 'telegram-chat_id' => '222']);
        $record = $this->record($app, $owner, 'telegram');
        self::assertSame(['chat_id' => '222'], $record->values(), 'only the picked id is stored');
        $secrets = $this->service($app, NotificationSecrets::class);
        self::assertSame(self::TELEGRAM_TOKEN, $secrets->open($owner->id, 'telegram.token'));

        // A webhook on the bot: Telegram refuses, and the page says so in words.
        $this->http->statusFor['https://api.telegram.org'] = 409;
        $this->http->bodyFor['https://api.telegram.org'] = (string) json_encode([
            'ok' => false,
            'error_code' => 409,
            'description' => 'Conflict: can\'t use getUpdates method while webhook is active',
        ]);
        $find = static fn (): string => self::body($browser->post('/settings/notifications/telegram/chats'));
        self::assertStringContainsString('This bot has a webhook set', $find());

        // None yet.
        unset($this->http->statusFor['https://api.telegram.org']);
        $this->http->bodyFor['https://api.telegram.org'] = '{"ok":true,"result":[]}';
        self::assertStringContainsString('No messages yet.', $find());
    }

    public function testThePolicyAppliesToMattermostAndToTheFixedHosts(): void
    {
        $app = $this->app();
        $this->signedIn($app);
        $sam = $this->createMember($app, 'sam');
        $this->dns->hosts['mm.lan'] = ['192.168.1.40'];
        $settings = new ChannelSettings([], ['url' => 'http://mm.lan:8065/hooks/abc123']);
        $sender = $this->sender($app, MattermostSender::class);
        $destinations = $this->service($app, OutboundDestination::class);

        $sent = $sender->send(self::test(), Recipient::of($sam), $settings, true);
        self::assertTrue($sent->delivered, 'your network: allowed by default');
        self::assertSame(['mm.lan' => '192.168.1.40'], $this->http->requests[0]['resolve'], 'pinned');

        $destinations->savePolicy(MemberDestinations::Internet);
        $refused = $sender->send(self::test(), Recipient::of($sam), $settings, true);
        self::assertTrue($refused->refused);
        self::assertCount(1, $this->http->requests);

        // A fixed host is still checked: one that doesn't resolve is refused.
        unset($this->dns->hosts['api.telegram.org']);
        $telegram = $this->sender($app, TelegramSender::class)->send(
            self::test(),
            Recipient::of($sam),
            new ChannelSettings(['chat_id' => '1'], ['token' => self::TELEGRAM_TOKEN]),
            true,
        );
        self::assertTrue($telegram->refused);
        self::assertSame('api.telegram.org could not be found.', $telegram->error);
        self::assertCount(1, $this->http->requests);
    }

    public function testAllNineKindsSendAndOneFailingLeavesTheOthers(): void
    {
        $app = $this->app();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $this->giveAllNine($app, $owner);
        $this->http->bodyFor['https://slack.com'] = '{"ok":true}';
        $this->http->bodyFor['https://api.pushover.net'] = '{"status":1}';
        $this->http->statusFor['https://discord.com'] = 404;

        $report = $this->dispatch($app, $owner);

        self::assertSame(
            ['email', 'ntfy', 'gotify', 'personal-webhook', 'telegram', 'pushover', 'mattermost', 'slack'],
            $report->deliveredChannels(),
        );
        $discord = $this->record($app, $owner, 'discord');
        self::assertSame('failed', $discord->lastStatus);
        self::assertSame('notifications.reply.discord_gone', $discord->lastError);
        self::assertSame(1, $discord->failures);
        foreach (['telegram', 'pushover', 'mattermost', 'slack'] as $kind) {
            self::assertSame('ok', $this->record($app, $owner, $kind)->lastStatus, $kind);
        }

        $page = self::body($this->browserFor($app, 'owner')->get('/settings/notifications'));
        self::assertStringContainsString('That webhook no longer exists.', $page, 'the words, in the reader\'s language');
        $secrets = [
            self::TELEGRAM_TOKEN,
            'Discord_Secret-Token',
            self::PUSHOVER_TOKEN,
            self::PUSHOVER_USER,
            'mmsecrethook123',
            self::SLACK_TOKEN,
        ];
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $page);
        }
        foreach (['discord', 'mattermost'] as $kind) {
            self::assertStringContainsString('data-channel="' . $kind . '"', $page);
        }
        self::assertSame(4, substr_count($page, 'data-third-party'), 'Telegram, Discord, Pushover and Slack; not Mattermost');
    }

    public function testASavedRowThatBreaksItsRulesIsRefusedBeforeAnyRequestAndNotCounted(): void
    {
        $app = $this->app();
        $this->signedIn($app);
        $owner = $this->owner($app);
        // As a restore could bring back: a Slack channel that isn't one, and a Discord "webhook" on another host.
        $this->giveChannel($app, $owner, 'slack', ['channel' => 'garage'], ['token' => self::SLACK_TOKEN]);
        $this->giveChannel($app, $owner, 'discord', [], ['url' => 'https://discord.com.example.org/api/webhooks/1/x']);

        for ($i = 0; $i < ChannelRecord::SWITCH_OFF_AFTER + 1; $i++) {
            $this->dispatch($app, $owner);
        }

        self::assertSame([], $this->http->to('https://slack.com'));
        self::assertSame([], $this->http->to('https://discord.com.example.org'));
        self::assertNull($this->record($app, $owner, 'discord')->lastStatus, 'no destination: *Needs setup*, never sent');
        $record = $this->record($app, $owner, 'slack');
        self::assertSame('notifications.reply.invalid_settings', $record->lastError);
        self::assertSame(0, $record->failures, 'never counted (#258)');
        self::assertTrue($record->enabled);
        self::assertStringContainsString(
            'The saved settings are no longer valid.',
            self::body($this->browserFor($app, 'owner')->get('/settings/notifications')),
        );
    }

    public function testTokensNeverReachAnErrorTheLogOrAPage(): void
    {
        $app = $this->app();
        $browser = $this->signedIn($app);
        $owner = $this->owner($app);
        $this->giveChannel($app, $owner, 'telegram', ['chat_id' => '42'], ['token' => self::TELEGRAM_TOKEN]);
        $url = 'https://api.telegram.org/bot' . self::TELEGRAM_TOKEN . '/sendMessage';
        $this->http->errorFor['https://api.telegram.org'] = 'Idle timeout reached for "' . $url . '".';

        $this->dispatch($app, $owner);
        $record = $this->record($app, $owner, 'telegram');
        self::assertSame('The service did not answer in time.', $record->lastError);

        $test = ['intent' => 'test', 'telegram-chat_id' => '42'];
        $tested = self::body($browser->post('/settings/notifications/telegram', $test));
        self::assertStringContainsString('The service did not answer in time.', $tested);
        self::assertStringNotContainsString(self::TELEGRAM_TOKEN, $tested);
        self::assertStringNotContainsString('123456789:', $tested);

        $log = (string) @file_get_contents(Kernel::rootDir() . '/var/log/app.log');
        self::assertStringNotContainsString(self::TELEGRAM_TOKEN, $log);
    }

    public function testAMemberCannotTouchAnothersChannels(): void
    {
        $app = $this->app();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $this->giveChannel($app, $owner, 'telegram', ['chat_id' => '42'], ['token' => self::TELEGRAM_TOKEN]);
        $this->createMember($app, 'sam');
        $member = $this->browserFor($app, 'sam');

        // Sam's Find my chat has no token: the owner's is never used.
        self::assertSame(422, $member->post('/settings/notifications/telegram/chats')->getStatusCode());
        self::assertSame(404, $member->post('/settings/notifications/telegram/switch', ['enabled' => '0'])->getStatusCode());
        self::assertStringNotContainsString('value="42"', self::body($member->get('/settings/notifications')));
        self::assertSame([], $this->http->requests);
        self::assertTrue($this->record($app, $owner, 'telegram')->enabled);
    }

    public function testFindMyChatWorksBehindASubpath(): void
    {
        $app = $this->createRecordingApp(self::ENV + ['APP_BASE_PATH' => '/logbook']);
        $this->dns->hosts['api.telegram.org'] = ['149.154.167.220'];
        $browser = $this->signedIn($app);
        $this->giveChannel($app, $this->owner($app), 'telegram', [], ['token' => self::TELEGRAM_TOKEN]);
        $this->http->bodyFor['https://api.telegram.org'] = (string) json_encode(['ok' => true, 'result' => [
            ['update_id' => 1, 'message' => ['chat' => ['id' => 111, 'type' => 'private', 'first_name' => 'Gareth']]],
        ]]);

        // Hard refresh with the prefix stripped by the proxy.
        self::assertStringContainsString(
            'action="/logbook/settings/notifications/telegram/chats"',
            self::body($browser->get('/settings/notifications')),
        );
        $found = self::body($browser->post('/settings/notifications/telegram/chats'));
        self::assertStringContainsString('action="/logbook/settings/notifications/telegram"', $found);
        self::assertStringContainsString('value="111"', $found);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function giveAllNine(App $app, User $owner): void
    {
        $this->giveChannel($app, $owner, 'ntfy', ['url' => 'https://ntfy.test/garage']);
        $this->giveChannel($app, $owner, 'gotify', ['url' => 'https://gotify.test', 'priority' => 5], ['token' => 'AppToken1']);
        $this->giveChannel($app, $owner, 'personal-webhook', ['url' => 'https://hooks.test/mine']);
        $this->giveChannel($app, $owner, 'telegram', ['chat_id' => '42'], ['token' => self::TELEGRAM_TOKEN]);
        $this->giveChannel($app, $owner, 'discord', [], ['url' => self::DISCORD_URL]);
        $this->giveChannel($app, $owner, 'pushover', [], ['token' => self::PUSHOVER_TOKEN, 'user' => self::PUSHOVER_USER]);
        $this->giveChannel($app, $owner, 'mattermost', [], ['url' => self::MATTERMOST_URL]);
        $this->giveChannel($app, $owner, 'slack', ['channel' => 'C0123456789'], ['token' => self::SLACK_TOKEN]);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function app(): App
    {
        $app = $this->createRecordingApp(self::ENV);
        $this->dns->hosts += [
            'api.telegram.org' => ['149.154.167.220'],
            'discord.com' => ['162.159.135.232'],
            'api.pushover.net' => ['104.20.42.54'],
            'chat.example.com' => ['203.0.113.30'],
            'slack.com' => ['3.120.10.10'],
        ];

        return $app;
    }

    /**
     * @template T of PersonalSender
     * @param App<ContainerInterface> $app
     * @param class-string<T> $class
     * @return T
     */
    private function sender(App $app, string $class): PersonalSender
    {
        return $this->service($app, $class);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function recipient(App $app): Recipient
    {
        return new Recipient(1, 'Solo');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function dispatch(App $app, User $user): DispatchReport
    {
        return $this->service($app, NotificationDispatcher::class)->dispatch(
            self::reminder(false),
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

    private static function reminder(bool $overdue): Notification
    {
        $status = $overdue ? 'overdue' : 'due';

        return new Notification(
            NotificationKind::Reminders,
            'MOT: ' . $status,
            "These need your attention:\n\n• MOT — @everyone *Golf*: " . $status,
            self::LINK,
            urgent: $overdue,
            items: [new NotificationItem(1, 'MOT — @everyone *Golf*', $status, $status, '2026-10-01')],
            locale: 'en',
        );
    }

    private static function digest(): Notification
    {
        return new Notification(NotificationKind::Digest, 'Due in October 2026', "One thing is due:\n\n• MOT: due", self::LINK);
    }

    private static function priceAlert(): Notification
    {
        return new Notification(NotificationKind::PriceAlert, 'Diesel at 139.9p', 'Below your 140p alert.', self::LINK);
    }

    private static function test(): Notification
    {
        $message = 'Hello, notifications from Logbook reach you here.';

        return new Notification(NotificationKind::Test, 'Logbook test notification', $message, self::LINK);
    }
}
