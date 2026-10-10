<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Notification\Personal;

use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationKind;
use Logbook\Service\Notification\Outbound\HttpAnswer;
use Logbook\Service\Notification\Outbound\OutboundHttp;
use Logbook\Service\Notification\Personal\ChannelForm;
use Logbook\Service\Notification\Personal\ChannelSettings;
use Logbook\Service\Notification\Personal\DiscordSender;
use Logbook\Service\Notification\Personal\GotifySender;
use Logbook\Service\Notification\Personal\MattermostSender;
use Logbook\Service\Notification\Personal\NtfySender;
use Logbook\Service\Notification\Personal\PersonalSender;
use Logbook\Service\Notification\Personal\PushoverSender;
use Logbook\Service\Notification\Personal\ReplyWords;
use Logbook\Service\Notification\Personal\ServiceText;
use Logbook\Service\Notification\Personal\SlackSender;
use Logbook\Service\Notification\Personal\TelegramSender;
use Logbook\Service\Notification\Personal\WebhookSender;
use Logbook\Service\Notification\Urgency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The Phase 36.3 services' own rules (spec.md §7.11): what each accepts,
 * how loudly each kind arrives, how user text is made inert, and each
 * service's answers in words, none of which holds a token.
 */
final class ServiceRulesTest extends TestCase
{
    private const string TELEGRAM_TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    public function testUrgencyOfEveryKind(): void
    {
        $urgency = static fn (NotificationKind $kind, bool $urgent = false): Urgency
            => (new Notification($kind, 't', 'm', urgent: $urgent))->urgency();

        self::assertSame(Urgency::High, $urgency(NotificationKind::Reminders, true), 'overdue');
        self::assertSame(Urgency::Normal, $urgency(NotificationKind::Reminders), 'due');
        self::assertSame(Urgency::Low, $urgency(NotificationKind::Digest));
        self::assertSame(Urgency::Normal, $urgency(NotificationKind::PriceAlert));
        self::assertSame(Urgency::High, $urgency(NotificationKind::JobFailed, true), '#260');
        self::assertSame(Urgency::Normal, $urgency(NotificationKind::ChannelOff, true), '#260: urgent for ntfy, normal here');
        self::assertSame(Urgency::Normal, $urgency(NotificationKind::Test));
    }

    public function testPushoverPriorityIsNeverEmergency(): void
    {
        $priorities = array_map(PushoverSender::priority(...), Urgency::cases());

        self::assertSame([-1, 0, 1], $priorities);
        self::assertNotContains(2, $priorities);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function discordUrls(): iterable
    {
        yield 'discord.com' => ['https://discord.com/api/webhooks/123456789012345678/abc_DEF-123', true];
        yield 'discordapp.com' => ['https://discordapp.com/api/webhooks/1/x', true];
        yield 'ptb' => ['https://ptb.discord.com/api/webhooks/1/x', true];
        yield 'canary' => ['https://canary.discord.com/api/webhooks/1/x', true];
        yield 'a look-alike host' => ['https://discord.com.example.org/api/webhooks/1/x', false];
        yield 'a subdomain' => ['https://evil.discord.com/api/webhooks/1/x', false];
        yield 'http' => ['http://discord.com/api/webhooks/1/x', false];
        yield 'a query' => ['https://discord.com/api/webhooks/1/x?wait=true', false];
        yield 'an empty query' => ['https://discord.com/api/webhooks/1/x?', false];
        yield 'a port' => ['https://discord.com:8443/api/webhooks/1/x', false];
        yield 'credentials' => ['https://u:p@discord.com/api/webhooks/1/x', false];
        yield 'a fragment' => ['https://discord.com/api/webhooks/1/x#y', false];
        yield 'not a webhook path' => ['https://discord.com/api/channels/1/messages', false];
        yield 'a non-numeric id' => ['https://discord.com/api/webhooks/abc/x', false];
        yield 'an extra segment' => ['https://discord.com/api/webhooks/1/x/slack', false];
    }

    #[DataProvider('discordUrls')]
    public function testDiscordAcceptsOnlyItsOwnWebhookUrls(string $url, bool $valid): void
    {
        self::assertSame($valid, DiscordSender::isWebhook($url));
        self::assertSame(
            $valid ? [] : ['url' => 'notifications.discord.url_invalid'],
            self::sender(DiscordSender::class)->validate([], ['url' => $url]),
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function mattermostUrls(): iterable
    {
        yield 'https' => ['https://chat.example.com/hooks/xyz123abc', true];
        yield 'a LAN host over http' => ['http://192.168.1.20:8065/hooks/abc', true];
        yield 'a subpath' => ['https://example.com/mattermost/hooks/abc', true];
        yield 'not ending in /hooks/{id}' => ['https://chat.example.com/hooks/abc/extra', false];
        yield 'no id' => ['https://chat.example.com/hooks/', false];
        yield 'another path' => ['https://chat.example.com/api/v4/posts', false];
        yield 'a query' => ['https://chat.example.com/hooks/abc?x=1', false];
        yield 'ftp' => ['ftp://chat.example.com/hooks/abc', false];
    }

    #[DataProvider('mattermostUrls')]
    public function testMattermostNeedsAnIncomingWebhookUrl(string $url, bool $valid): void
    {
        self::assertSame($valid, MattermostSender::isWebhook($url));
    }

    public function testEachServicesFieldsAreChecked(): void
    {
        $telegram = self::sender(TelegramSender::class);
        self::assertSame([], $telegram->validate(['chat_id' => '-1001234567890'], ['token' => self::TELEGRAM_TOKEN]));
        self::assertSame([], $telegram->validate(['chat_id' => '@my_channel']));
        $short = $telegram->validate(['chat_id' => '@abcd']);
        self::assertSame(['chat_id' => 'notifications.telegram.chat_id_invalid'], $short, 'usernames are 5 to 32');
        self::assertSame(
            ['token' => 'notifications.telegram.token_invalid', 'chat_id' => 'notifications.telegram.chat_id_invalid'],
            $telegram->validate(['chat_id' => '12ab'], ['token' => '123:short']),
        );

        $pushover = self::sender(PushoverSender::class);
        $key = str_repeat('a1B2c', 6);
        self::assertSame([], $pushover->validate(['device' => 'iphone,pixel_7'], ['token' => $key, 'user' => $key]));
        self::assertSame(
            [
                'token' => 'notifications.pushover.key_invalid',
                'user' => 'notifications.pushover.key_invalid',
                'device' => 'notifications.pushover.device_invalid',
            ],
            $pushover->validate(['device' => 'my phone'], ['token' => 'short', 'user' => $key . 'x']),
        );

        $slack = self::sender(SlackSender::class);
        self::assertSame([], $slack->validate(['channel' => 'C0123456789'], ['token' => 'xoxb-1234567890-abcdefghij']));
        self::assertSame([], $slack->validate(['channel' => '#garage']));
        self::assertSame(
            ['token' => 'notifications.slack.token_invalid', 'channel' => 'notifications.slack.channel_invalid'],
            $slack->validate(['channel' => 'garage'], ['token' => 'xoxp-1234567890-abcdefghij']),
        );

        $mattermost = self::sender(MattermostSender::class);
        self::assertSame([], $mattermost->validate(['channel' => '@sam.jones']));
        self::assertSame(
            ['channel' => 'notifications.mattermost.channel_invalid'],
            $mattermost->validate(['channel' => 'town square']),
        );
    }

    public function testSavedSettingsAreReCheckedAsIfTypedAgain(): void
    {
        $discord = self::sender(DiscordSender::class);
        $url = static fn (string $url): ChannelSettings => new ChannelSettings([], ['url' => $url]);

        self::assertTrue(ChannelForm::stillValid($discord, $url('https://discord.com/api/webhooks/1/x')));
        self::assertFalse(ChannelForm::stillValid($discord, $url('https://discord.com.example.org/api/webhooks/1/x')), '#258');
        self::assertFalse(ChannelForm::stillValid($discord, $url('env:SECRET')));
        $noToken = new ChannelSettings(['channel' => 'C0123456789'], []);
        self::assertFalse(ChannelForm::stillValid(self::sender(SlackSender::class), $noToken), 'a required secret is missing');
    }

    public function testDiscordTextCannotFormatOrMaskALink(): void
    {
        self::assertSame(
            '\\[Renew here\\]\\(https://example.test/a\\)',
            DiscordSender::escape('[Renew here](https://example.test/a)'),
        );
        self::assertSame(
            '\\*\\*bold\\*\\* \\_it\\_ \\_\\_under\\_\\_ \\~\\~struck\\~\\~ \\|\\|spoiler\\|\\| \\`code\\`',
            DiscordSender::escape('**bold** _it_ __under__ ~~struck~~ ||spoiler|| `code`'),
        );
        self::assertSame(
            '\\<@123\\> \\<#456\\> \\<t:1700000000:R\\> \\<https://example.test\\>',
            DiscordSender::escape('<@123> <#456> <t:1700000000:R> <https://example.test>'),
        );
        self::assertSame('a \\\\ b', DiscordSender::escape('a \\ b'), 'the backslash itself');
        self::assertSame('@everyone', DiscordSender::escape('@everyone'), 'allowed_mentions stops the ping; no ZWSP');

        $starts = ['# heading', '## heading', '-# subtext', '> quote', '>>> quote', '- list', '+ list', '* list', '1. list'];
        foreach ($starts as $line) {
            $escaped = DiscordSender::escape($line);
            self::assertStringStartsWith('\\', ltrim($escaped, '0123456789'), $line);
            self::assertSame("x\n" . $escaped, DiscordSender::escape("x\n" . $line), 'after a newline too: ' . $line);
        }
        self::assertSame('1\\. list', DiscordSender::escape('1. list'));
        $plain = 'Diesel at 139.9p — £12.50 # a';
        self::assertSame($plain, DiscordSender::escape($plain), 'plain text stays readable');
    }

    public function testMattermostTextCannotMentionOrFormat(): void
    {
        $escaped = MattermostSender::escape('@everyone @here @channel @all @sam <!channel> **bold** [link](x) `code` # heading');

        foreach (['@everyone', '@here', '@channel', '@all', '@sam', '<!channel>'] as $mention) {
            self::assertStringNotContainsString($mention, $escaped, $mention);
        }
        self::assertStringContainsString('@' . MattermostSender::ZWSP . 'here', $escaped);
        self::assertStringContainsString('\\*\\*bold\\*\\*', $escaped);
        self::assertStringContainsString('\\[link\\]\\(x\\)', $escaped);
        self::assertStringContainsString('\\`code\\`', $escaped);
        self::assertSame('\\- not a list', MattermostSender::escape('- not a list'));
        self::assertSame('1\\. not a list', MattermostSender::escape('1. not a list'));

        $lines = MattermostSender::lines(
            new Notification(
                NotificationKind::Reminders,
                'Service — @here Golf',
                "These need your attention:\n\n• MOT — *Golf*: due",
            ),
        );
        self::assertSame('**Service — @' . MattermostSender::ZWSP . 'here Golf**', $lines[0]);
        self::assertSame('- MOT — \\*Golf\\*: due', $lines[4], 'a reminder is a list item');
    }

    public function testEveryCardsIconIsInTheSprite(): void
    {
        $sprite = (string) file_get_contents(dirname(__DIR__, 5) . '/assets/vendor/icons.svg');
        $classes = [
            TelegramSender::class,
            DiscordSender::class,
            PushoverSender::class,
            MattermostSender::class,
            SlackSender::class,
        ];
        $http = (new ReflectionClass(OutboundHttp::class))->newInstanceWithoutConstructor();
        $senders = [
            new NtfySender($http),
            new GotifySender($http),
            new WebhookSender($http),
            ...array_map(self::sender(...), $classes),
        ];

        foreach ($senders as $sender) {
            $icon = $sender->definition()->icon;
            $key = $sender->definition()->key;
            self::assertStringContainsString('<symbol id="' . $icon . '"', $sprite, $key . ': ' . $icon);
        }
    }

    public function testSlackTextCannotFormAMentionOrALink(): void
    {
        self::assertSame(
            '&lt;!channel&gt; &lt;@U123&gt; &lt;!here&gt; a &amp;amp; b',
            SlackSender::escape('<!channel> <@U123> <!here> a &amp; b'),
        );
    }

    public function testRepliesAreStoredAsWordsAndReadBack(): void
    {
        self::assertSame('notifications.reply.wait|30', ReplyWords::of('wait', 30));
        $wait = ['key' => 'notifications.reply.wait', 'params' => ['seconds' => 30]];
        self::assertSame($wait, ReplyWords::decode('notifications.reply.wait|30'));
        $gone = ['key' => 'notifications.reply.discord_gone', 'params' => []];
        self::assertSame($gone, ReplyWords::decode(ReplyWords::of('discord_gone')));
        self::assertNull(ReplyWords::decode('HTTP 500'));
        self::assertNull(ReplyWords::decode('notifications.reply.x|y'));
        self::assertSame('notifications.reply.busy', ReplyWords::of('wait', 0), 'no usable Retry-After');
    }

    /**
     * @return iterable<string, array{
     *     class-string<TelegramSender|DiscordSender|PushoverSender|MattermostSender|SlackSender>,
     *     HttpAnswer,
     *     string|null,
     * }>
     */
    public static function answers(): iterable
    {
        $a = HttpAnswer::answered(...);
        $w = ReplyWords::of(...);
        $t = TelegramSender::class;
        yield 'Telegram ok' => [$t, $a(200, ['ok' => true]), null];
        yield 'Telegram 401' => [$t, $a(401), $w('telegram_token')];
        yield 'Telegram 404' => [$t, $a(404), $w('telegram_token')];
        yield 'Telegram 403' => [$t, $a(403), $w('telegram_blocked')];
        yield 'Telegram chat not found' => [$t, $a(400, ['description' => 'Bad Request: chat not found']), $w('telegram_chat')];
        yield 'Telegram 400' => [$t, $a(400, ['description' => 'Bad Request: message is too long']), $w('telegram_refused')];
        yield 'Telegram 429' => [$t, $a(429, ['parameters' => ['retry_after' => 17]]), $w('wait', 17)];
        yield 'Telegram 409' => [$t, $a(409), 'HTTP 409'];
        $d = DiscordSender::class;
        yield 'Discord 204' => [$d, $a(204), null];
        yield 'Discord 404' => [$d, $a(404), $w('discord_gone')];
        yield 'Discord 401' => [$d, $a(401), $w('discord_gone')];
        yield 'Discord 400' => [$d, $a(400), $w('discord_refused')];
        yield 'Discord 429' => [$d, $a(429, ['retry_after' => 1.2]), $w('wait', 2)];
        yield 'Discord 429, header' => [$d, $a(429, [], 5), $w('wait', 5)];
        yield 'Discord 500' => [$d, $a(500), 'HTTP 500'];
        $p = PushoverSender::class;
        yield 'Pushover ok' => [$p, $a(200, ['status' => 1]), null];
        yield 'Pushover 200, status 0' => [$p, $a(200, ['status' => 0]), $w('pushover_refused')];
        $said = static fn (string $error): HttpAnswer => HttpAnswer::answered(400, ['status' => 0, 'errors' => [$error]]);
        yield 'Pushover bad user' => [$p, $said('user identifier is invalid'), $w('pushover_keys')];
        yield 'Pushover bad token' => [$p, $said('application token is invalid'), $w('pushover_keys')];
        yield 'Pushover 400' => [$p, $a(400, ['status' => 0, 'errors' => ['message cannot be blank']]), $w('pushover_refused')];
        yield 'Pushover 429' => [$p, $a(429), $w('pushover_quota')];
        $m = MattermostSender::class;
        yield 'Mattermost ok' => [$m, $a(200), null];
        yield 'Mattermost 400' => [$m, $a(400), $w('mattermost_refused')];
        yield 'Mattermost 403' => [$m, $a(403), $w('mattermost_refused')];
        yield 'Mattermost 404' => [$m, $a(404), $w('mattermost_gone')];
        yield 'Mattermost 429' => [$m, $a(429, [], 3), $w('wait', 3)];
        $s = SlackSender::class;
        yield 'Slack ok' => [$s, $a(200, ['ok' => true]), null];
        yield 'Slack invalid_auth' => [$s, $a(200, ['ok' => false, 'error' => 'invalid_auth']), $w('slack_token')];
        yield 'Slack token_revoked' => [$s, $a(200, ['ok' => false, 'error' => 'token_revoked']), $w('slack_token')];
        yield 'Slack not_in_channel' => [$s, $a(200, ['ok' => false, 'error' => 'not_in_channel']), $w('slack_not_in_channel')];
        yield 'Slack channel_not_found' => [$s, $a(200, ['ok' => false, 'error' => 'channel_not_found']), $w('slack_channel')];
        yield 'Slack is_archived' => [$s, $a(200, ['ok' => false, 'error' => 'is_archived']), $w('slack_archived')];
        yield 'Slack ratelimited' => [$s, $a(200, ['ok' => false, 'error' => 'ratelimited'], 9), $w('wait', 9)];
        yield 'Slack 429' => [$s, $a(429, [], 30), $w('wait', 30)];
        yield 'Slack another code' => [$s, $a(200, ['ok' => false, 'error' => 'msg_too_long']), 'Slack: msg_too_long'];
        yield 'Slack a strange code' => [$s, $a(200, ['ok' => false, 'error' => 'xoxb-leak <b>']), 'Slack: unknown'];
        foreach ([$t, $d, $p, $m, $s] as $class) {
            $name = (new ReflectionClass($class))->getShortName();
            yield $name . ' redirect' => [$class, $a(302), $w('redirect')];
            $timeout = OutboundHttp::connectionError('Idle timeout reached for "https://x/bot' . self::TELEGRAM_TOKEN . '"');
            yield $name . ' timeout' => [$class, HttpAnswer::unreachable($timeout), 'The service did not answer in time.'];
            $gone = 'x.example could not be found.';
            yield $name . ' refused' => [$class, HttpAnswer::refused($gone), $gone];
        }
    }

    /**
     * @param class-string<TelegramSender|DiscordSender|PushoverSender|MattermostSender|SlackSender> $class
     */
    #[DataProvider('answers')]
    public function testEachServicesAnswersInWords(string $class, HttpAnswer $answer, ?string $expected): void
    {
        $words = static function (HttpAnswer $answer) use ($class): ?string {
            $said = $class::words($answer);

            return is_string($said) ? $said : null;
        };
        $result = ReplyWords::result('x', $answer, $words);

        self::assertSame($expected === null, $result->delivered);
        self::assertSame($expected, $result->error);
        self::assertSame($answer->refused, $result->refused, 'a refusal before any request is never counted');
        self::assertStringNotContainsString(self::TELEGRAM_TOKEN, (string) $result->error);
    }

    public function testConnectionErrorsNeverQuoteTheUrl(): void
    {
        $url = 'https://api.telegram.org/bot' . self::TELEGRAM_TOKEN . '/sendMessage';
        $cases = [
            'Idle timeout reached for "' . $url . '".' => 'The service did not answer in time.',
            'Could not resolve host: ' . $url => 'The service\'s name could not be found.',
            'SSL certificate problem for "' . $url . '"' => 'A secure connection could not be made.',
            'Failed to connect to ' . $url => 'The service could not be reached.',
        ];
        foreach ($cases as $message => $words) {
            self::assertSame($words, OutboundHttp::connectionError($message));
        }
    }

    public function testServiceTextAddsItsOwnWords(): void
    {
        $text = new ServiceText(new IdentityTranslator());
        $notification = new Notification(NotificationKind::Test, 'Title', "Line one\nLine two", locale: 'en');

        self::assertSame('notifications.more', ($text->more($notification))(3));
        self::assertSame('notifications.open_link', $text->openLink($notification));
        self::assertSame(['Title', '', 'Line one', 'Line two'], ServiceText::plainLines($notification));
    }

    /**
     * @template T of PersonalSender
     * @param class-string<T> $class
     * @return T
     */
    private static function sender(string $class): PersonalSender
    {
        $http = (new ReflectionClass(OutboundHttp::class))->newInstanceWithoutConstructor();

        return new $class($http, new ServiceText(new IdentityTranslator()));
    }
}
