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
 * Discord (spec.md §7.11): a channel's webhook URL, a secret because its
 * token is in the path. Only Discord's own hosts are accepted. Mentions in
 * the text ping nobody (`allowed_mentions` with an empty `parse`), and
 * link previews are suppressed.
 */
final readonly class DiscordSender implements PersonalSender
{
    public const string KEY = 'discord';
    public const int LIMIT = 2000;
    /** SUPPRESS_EMBEDS: no link preview. */
    public const int FLAGS = 4;
    private const array HOSTS = ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com'];

    public function __construct(private OutboundHttp $http, private ServiceText $text)
    {
    }

    public function definition(): ChannelDefinition
    {
        return new ChannelDefinition(self::KEY, 'Discord', 'forum', 'notifications.discord.hint', [
            new ChannelField(
                'url',
                'notifications.discord.url',
                FieldType::Secret,
                required: true,
                hint: 'notifications.discord.url_hint',
                maxLength: 300,
            ),
        ], thirdParty: true);
    }

    public function destination(ChannelSettings $settings): ?string
    {
        $url = $settings->secret('url');

        if ($url === null || !self::isWebhook($url)) {
            return null;
        }

        return 'https://' . strtolower((string) parse_url($url, PHP_URL_HOST)) . '/';
    }

    public function validate(array $values, #[SensitiveParameter] array $secrets = []): array
    {
        return isset($secrets['url']) && !self::isWebhook($secrets['url']) ? ['url' => 'notifications.discord.url_invalid'] : [];
    }

    /**
     * https, one of Discord's hosts exactly, no port, user, query or
     * fragment, and the path `/api/webhooks/{id}/{token}`.
     */
    public static function isWebhook(#[SensitiveParameter] string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ChannelForm::url($url) !== null) {
            return false;
        }

        return strtolower($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)
            && !isset($parts['port'])
            && !isset($parts['query'])
            && !str_contains($url, '?')
            && preg_match('#^/api/webhooks/\d{1,25}/[A-Za-z0-9_-]{1,200}$#', $parts['path'] ?? '') === 1;
    }

    public function send(
        Notification $notification,
        Recipient $recipient,
        ChannelSettings $settings,
        bool $restricted,
    ): DeliveryResult {
        $url = $settings->secret('url');
        if ($url === null || !self::isWebhook($url)) {
            return DeliveryResult::failed(self::KEY, ReplyWords::of('needs_setup'));
        }

        $payload = [
            'content' => MessageText::fit(
                ServiceText::plainLines($notification),
                self::LIMIT,
                $notification->url,
                $this->text->more($notification),
            ),
            'username' => 'Logbook',
            'allowed_mentions' => ['parse' => []],
            'flags' => self::FLAGS,
        ];
        $answer = $this->http->send('POST', $url, ['json' => $payload], $restricted);

        return ReplyWords::result(self::KEY, $answer, self::words(...));
    }

    /**
     * Discord's errors in words (spec.md §7.11); null when it worked.
     */
    public static function words(HttpAnswer $answer): ?string
    {
        $retry = $answer->body['retry_after'] ?? null;
        $seconds = is_numeric($retry) ? (int) ceil((float) $retry) : ($answer->retryAfter ?? 0);

        return match (true) {
            $answer->ok() => null,
            $answer->status === 404, $answer->status === 401 => ReplyWords::of('discord_gone'),
            $answer->status === 429 => ReplyWords::of('wait', $seconds),
            $answer->status === 400 => ReplyWords::of('discord_refused'),
            default => null,
        };
    }
}
