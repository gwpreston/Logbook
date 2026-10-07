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
 * Mattermost (spec.md §7.11): the user's incoming-webhook URL (a secret)
 * on their own server, which the destination policy classes like any
 * member's address, and an optional channel. The text is Markdown: the
 * title bold, the reminders a list, and everything users typed escaped
 * with its mentions neutralised, so it can't ping anyone.
 */
final readonly class MattermostSender implements PersonalSender
{
    public const string KEY = 'mattermost';
    public const int LIMIT = 16383;
    /** Zero-width space: placed after `@` and `<!` it stops a mention. */
    public const string ZWSP = "\u{200B}";

    public function __construct(private OutboundHttp $http, private ServiceText $text)
    {
    }

    public function definition(): ChannelDefinition
    {
        return new ChannelDefinition(self::KEY, 'Mattermost', 'chat', 'notifications.mattermost.hint', [
            new ChannelField(
                'url',
                'notifications.mattermost.url',
                FieldType::Secret,
                required: true,
                hint: 'notifications.mattermost.url_hint',
                maxLength: 500,
            ),
            new ChannelField(
                'channel',
                'notifications.mattermost.channel',
                FieldType::Text,
                hint: 'notifications.mattermost.channel_hint',
                placeholder: 'town-square',
                maxLength: 65,
            ),
        ]);
    }

    public function destination(ChannelSettings $settings): ?string
    {
        $url = $settings->secret('url');
        $parts = $url === null || !self::isWebhook($url) ? false : parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . $port . '/';
    }

    public function validate(array $values, #[SensitiveParameter] array $secrets = []): array
    {
        $errors = [];
        if (isset($secrets['url']) && !self::isWebhook($secrets['url'])) {
            $errors['url'] = 'notifications.mattermost.url_invalid';
        }
        $channel = $values['channel'] ?? '';
        if ($channel !== '' && preg_match('/^@?[A-Za-z0-9._-]{1,64}$/', $channel) !== 1) {
            $errors['channel'] = 'notifications.mattermost.channel_invalid';
        }

        return $errors;
    }

    /**
     * http(s), a host, no credentials, query or fragment, and a path that
     * ends in `/hooks/{id}` (a Mattermost under a subpath works).
     */
    public static function isWebhook(#[SensitiveParameter] string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ChannelForm::url($url) === null
            && !isset($parts['query'])
            && !str_contains($url, '?')
            && preg_match('#/hooks/[A-Za-z0-9]{1,64}$#', $parts['path'] ?? '') === 1;
    }

    /**
     * Text another user typed, made inert for Markdown and mentions:
     * `@name`, `@channel`, `@here`, `@all` and `<!channel>` get a zero-width
     * space, and Markdown's characters a backslash.
     */
    public static function escape(string $text): string
    {
        $text = str_replace(['<!', '@'], ['<!' . self::ZWSP, '@' . self::ZWSP], $text);
        $text = (string) preg_replace('/([\\\\`*_\[\]()#|<>~!])/u', '\\\\$1', $text);

        // A line can't start a list (headings and quotes are escaped above).
        $text = (string) preg_replace('/^(\s*)([-+])(\s)/u', '$1\\\\$2$3', $text);

        return (string) preg_replace('/^(\s*\d+)\.(\s)/u', '$1\\.$2', $text);
    }

    /**
     * The title bold, then the body with each "• " line as a list item.
     *
     * @return list<string>
     */
    public static function lines(Notification $notification): array
    {
        $lines = ['**' . self::escape($notification->title) . '**', ''];
        foreach ($notification->lines() as $line) {
            $lines[] = str_starts_with($line, '• ')
                ? '- ' . self::escape(substr($line, strlen('• ')))
                : self::escape($line);
        }

        return $lines;
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

        $more = $this->text->more($notification);
        $payload = [
            'text' => MessageText::fit(
                self::lines($notification),
                self::LIMIT,
                $notification->url,
                // A blank line first, so it isn't read as part of the last list item.
                static fn (int $count): string => "\n" . self::escape($more($count)),
            ),
        ];
        $channel = $settings->value('channel');
        if ($channel !== null) {
            $payload['channel'] = $channel;
        }
        $answer = $this->http->send('POST', $url, ['json' => $payload], $restricted);

        return ReplyWords::result(self::KEY, $answer, self::words(...));
    }

    /**
     * Mattermost's errors in words (spec.md §7.11); null when it worked.
     */
    public static function words(HttpAnswer $answer): ?string
    {
        return match (true) {
            $answer->ok() => null,
            $answer->status === 400, $answer->status === 403 => ReplyWords::of('mattermost_refused'),
            $answer->status === 404 => ReplyWords::of('mattermost_gone'),
            $answer->status === 429 => ReplyWords::of('wait', $answer->retryAfter ?? 0),
            default => null,
        };
    }
}
