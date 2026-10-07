<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\Outbound\HttpAnswer;
use Logbook\Service\Notification\Outbound\OutboundHttp;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Notification\Urgency;
use SensitiveParameter;

/**
 * Telegram (spec.md §7.11): the user's own bot token and chat ID.
 * `sendMessage` as plain text (no `parse_mode`, so a vehicle name can't
 * inject formatting), link previews off, the digest without a sound. The
 * token is in the request's path, so no error ever quotes the URL.
 */
final readonly class TelegramSender implements PersonalSender, VerifiesSettings
{
    public const string KEY = 'telegram';
    public const string API = 'https://api.telegram.org';
    public const int LIMIT = 4096;
    private const string TOKEN = '/^\d{1,20}:[A-Za-z0-9_-]{30,}$/';
    private const string CHAT = '/^(-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/';

    public function __construct(private OutboundHttp $http, private ServiceText $text)
    {
    }

    public function definition(): ChannelDefinition
    {
        return new ChannelDefinition(self::KEY, 'Telegram', 'send', 'notifications.telegram.hint', [
            new ChannelField(
                'token',
                'notifications.telegram.token',
                FieldType::Secret,
                required: true,
                hint: 'notifications.telegram.token_hint',
                maxLength: 100,
            ),
            new ChannelField(
                'chat_id',
                'notifications.telegram.chat_id',
                FieldType::Text,
                hint: 'notifications.telegram.chat_id_hint',
                placeholder: '123456789',
                maxLength: 33,
                neededToSend: true,
            ),
        ], thirdParty: true);
    }

    public function destination(ChannelSettings $settings): string
    {
        return self::API . '/';
    }

    public function validate(array $values, #[SensitiveParameter] array $secrets = []): array
    {
        $errors = [];
        if (isset($secrets['token']) && preg_match(self::TOKEN, $secrets['token']) !== 1) {
            $errors['token'] = 'notifications.telegram.token_invalid';
        }
        if (isset($values['chat_id']) && $values['chat_id'] !== '' && preg_match(self::CHAT, $values['chat_id']) !== 1) {
            $errors['chat_id'] = 'notifications.telegram.chat_id_invalid';
        }

        return $errors;
    }

    public function send(
        Notification $notification,
        Recipient $recipient,
        ChannelSettings $settings,
        bool $restricted,
    ): DeliveryResult {
        $token = $settings->secret('token');
        $chat = $settings->value('chat_id');
        if ($token === null || $chat === null) {
            return DeliveryResult::failed(self::KEY, ReplyWords::of('needs_setup'));
        }

        $payload = [
            'chat_id' => $chat,
            'text' => MessageText::fit(
                ServiceText::plainLines($notification),
                self::LIMIT,
                $notification->url,
                $this->text->more($notification),
            ),
            'link_preview_options' => ['is_disabled' => true],
        ];
        if ($notification->urgency() === Urgency::Low) {
            $payload['disable_notification'] = true;
        }

        $answer = $this->http->send('POST', self::method($token, 'sendMessage'), ['json' => $payload], $restricted);

        return ReplyWords::result(self::KEY, $answer, self::words(...));
    }

    public function verify(ChannelSettings $settings, bool $restricted): Verification
    {
        $token = $settings->secret('token');
        if ($token === null) {
            return Verification::unreachable();
        }
        $answer = $this->http->send('POST', self::method($token, 'getMe'), OutboundHttp::CHECK, $restricted);
        if ($answer->ok()) {
            $result = $answer->body['result'] ?? null;
            $username = is_array($result) && is_string($result['username'] ?? null) ? $result['username'] : null;

            return Verification::works($username === null ? null : '@' . $username);
        }

        return self::tokenRejected($answer)
            ? Verification::rejected(ReplyWords::of('telegram_token'))
            : Verification::unreachable();
    }

    /**
     * *Find my chat* (#237): the private chats that have written to the
     * bot, newest first, by `getUpdates` without an offset (nothing is
     * consumed). Only each chat's id and first name are read.
     */
    public function findChats(#[SensitiveParameter] string $token, bool $restricted): FoundChats
    {
        $url = self::method($token, 'getUpdates');
        $answer = $this->http->send('POST', $url, ['json' => ['limit' => 100]] + OutboundHttp::CHECK, $restricted);
        if ($answer->error !== null) {
            return FoundChats::failed($answer->error);
        }
        if ($answer->status === 409) {
            return FoundChats::failed(ReplyWords::of('telegram_webhook'));
        }
        if (!$answer->ok()) {
            return FoundChats::failed(self::words($answer) ?? sprintf('HTTP %d', (int) $answer->status));
        }

        $chats = [];
        $seen = [];
        $updates = $answer->body['result'] ?? [];
        foreach (is_array($updates) ? array_reverse($updates) : [] as $update) {
            if (!is_array($update)) {
                continue;
            }
            foreach (['message', 'edited_message', 'my_chat_member'] as $part) {
                $event = $update[$part] ?? null;
                $chat = is_array($event) && is_array($event['chat'] ?? null) ? $event['chat'] : null;
                if ($chat === null || ($chat['type'] ?? null) !== 'private' || !is_int($chat['id'] ?? null)) {
                    continue;
                }
                $id = (string) $chat['id'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $name = is_string($chat['first_name'] ?? null) ? $chat['first_name'] : '';
                    $chats[] = ['id' => $id, 'name' => MessageText::cut($name, 64)];
                }
            }
        }

        return FoundChats::found($chats);
    }

    private static function method(#[SensitiveParameter] string $token, string $method): string
    {
        return self::API . '/bot' . $token . '/' . $method;
    }

    private static function tokenRejected(HttpAnswer $answer): bool
    {
        return $answer->status === 401 || $answer->status === 404;
    }

    /**
     * Telegram's errors in words (spec.md §7.11); null when it worked.
     */
    public static function words(HttpAnswer $answer): ?string
    {
        if ($answer->ok()) {
            return null;
        }
        $description = strtolower($answer->string('description') ?? '');
        $parameters = $answer->body['parameters'] ?? null;
        $retry = is_array($parameters) && is_int($parameters['retry_after'] ?? null)
            ? $parameters['retry_after']
            : $answer->retryAfter;

        return match (true) {
            self::tokenRejected($answer) => ReplyWords::of('telegram_token'),
            $answer->status === 403 => ReplyWords::of('telegram_blocked'),
            $answer->status === 429 => ReplyWords::of('wait', $retry ?? 0),
            $answer->status === 400 && str_contains($description, 'chat not found') => ReplyWords::of('telegram_chat'),
            $answer->status === 400 => ReplyWords::of('telegram_refused'),
            default => null,
        };
    }
}
