<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\Outbound\HttpAnswer;
use Logbook\Service\Notification\Outbound\OutboundHttp;
use Logbook\Service\Notification\Recipient;
use SensitiveParameter;

/**
 * Slack (spec.md §7.11, #241): the user's own app's bot token and a
 * channel, through `chat.postMessage`. Plain text (`mrkdwn` off) with `&`,
 * `<` and `>` escaped and no `link_names`, so `<!channel>`, `<!here>` and
 * `<@U…>` can't be formed; no link unfurling. Slack answers 200 with
 * `ok: false` and a code on an error.
 */
final readonly class SlackSender implements PersonalSender, VerifiesSettings
{
    public const string KEY = 'slack';
    public const string API = 'https://slack.com/api/';
    public const int LIMIT = 4000;
    private const string TOKEN = '/^xoxb-[A-Za-z0-9-]{10,195}$/';
    private const string CHANNEL = '/^([CGD][A-Z0-9]{8,12}|#[a-z0-9_-]{1,80})$/';
    private const array TOKEN_ERRORS = ['invalid_auth', 'not_authed', 'token_revoked', 'token_expired', 'account_inactive'];

    public function __construct(private OutboundHttp $http, private ServiceText $text)
    {
    }

    public function definition(): ChannelDefinition
    {
        return new ChannelDefinition(self::KEY, 'Slack', 'tag', 'notifications.slack.hint', [
            new ChannelField(
                'token',
                'notifications.slack.token',
                FieldType::Secret,
                required: true,
                hint: 'notifications.slack.token_hint',
                maxLength: 200,
            ),
            new ChannelField(
                'channel',
                'notifications.slack.channel',
                FieldType::Text,
                required: true,
                hint: 'notifications.slack.channel_hint',
                placeholder: 'C0123456789',
                maxLength: 81,
            ),
        ], thirdParty: true);
    }

    public function destination(ChannelSettings $settings): string
    {
        return 'https://slack.com/';
    }

    public function validate(array $values, #[SensitiveParameter] array $secrets = []): array
    {
        $errors = [];
        if (isset($secrets['token']) && preg_match(self::TOKEN, $secrets['token']) !== 1) {
            $errors['token'] = 'notifications.slack.token_invalid';
        }
        if (isset($values['channel']) && $values['channel'] !== '' && preg_match(self::CHANNEL, $values['channel']) !== 1) {
            $errors['channel'] = 'notifications.slack.channel_invalid';
        }

        return $errors;
    }

    /** `&`, `<` and `>` as Slack's entities: nothing can form a mention or a link. */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    public function send(
        Notification $notification,
        Recipient $recipient,
        ChannelSettings $settings,
        bool $restricted,
    ): DeliveryResult {
        $token = $settings->secret('token');
        $channel = $settings->value('channel');
        if ($token === null || $channel === null) {
            return DeliveryResult::failed(self::KEY, ReplyWords::of('needs_setup'));
        }

        $more = $this->text->more($notification);
        $payload = [
            'channel' => $channel,
            'text' => MessageText::fit(
                array_map(self::escape(...), ServiceText::plainLines($notification)),
                self::LIMIT,
                $notification->url === null ? null : self::escape($notification->url),
                static fn (int $count): string => self::escape($more($count)),
            ),
            'mrkdwn' => false,
            'unfurl_links' => false,
            'unfurl_media' => false,
        ];
        $answer = $this->http->send('POST', self::API . 'chat.postMessage', [
            'json' => $payload,
            'auth_bearer' => $token,
        ], $restricted);

        return ReplyWords::result(self::KEY, $answer, self::words(...));
    }

    public function verify(ChannelSettings $settings, bool $restricted): Verification
    {
        $token = $settings->secret('token');
        if ($token === null) {
            return Verification::unreachable();
        }
        $options = ['auth_bearer' => $token] + OutboundHttp::CHECK;
        $answer = $this->http->send('POST', self::API . 'auth.test', $options, $restricted);
        if ($answer->ok() && ($answer->body['ok'] ?? false) === true) {
            return Verification::works($answer->string('team'));
        }

        return in_array($answer->string('error'), self::TOKEN_ERRORS, true)
            ? Verification::rejected(ReplyWords::of('slack_token'))
            : Verification::unreachable();
    }

    /**
     * Slack's errors in words (spec.md §7.11); null when it worked.
     */
    public static function words(HttpAnswer $answer): ?string
    {
        if ($answer->status === 429) {
            return ReplyWords::of('wait', $answer->retryAfter ?? 0);
        }
        if (!$answer->ok() || ($answer->body['ok'] ?? false) === true) {
            return null;
        }
        $code = $answer->string('error') ?? 'unknown';

        return match (true) {
            in_array($code, self::TOKEN_ERRORS, true) => ReplyWords::of('slack_token'),
            $code === 'not_in_channel' => ReplyWords::of('slack_not_in_channel'),
            $code === 'channel_not_found' => ReplyWords::of('slack_channel'),
            $code === 'is_archived' => ReplyWords::of('slack_archived'),
            $code === 'ratelimited' => ReplyWords::of('wait', $answer->retryAfter ?? 0),
            // Slack's own code, which never holds the token.
            default => 'Slack: ' . (preg_match('/^[a-z_]{1,64}$/', $code) === 1 ? $code : 'unknown'),
        };
    }
}
