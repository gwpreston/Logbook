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
 * Pushover (spec.md §7.11): the user's application token and user key,
 * and an optional device. Priority -1 for the digest, 0 normally, 1 for an
 * overdue reminder; emergency priority (2) is never used, since it repeats
 * until acknowledged.
 */
final readonly class PushoverSender implements PersonalSender, VerifiesSettings
{
    public const string KEY = 'pushover';
    public const string API = 'https://api.pushover.net/1/';
    public const int LIMIT = 1024;
    public const int TITLE_LIMIT = 250;
    public const int URL_LIMIT = 512;
    public const int URL_TITLE_LIMIT = 100;
    private const string KEY_FORMAT = '/^[A-Za-z0-9]{30}$/';
    private const string DEVICE = '/^[A-Za-z0-9_-]{1,25}(,[A-Za-z0-9_-]{1,25})*$/';

    public function __construct(private OutboundHttp $http, private ServiceText $text)
    {
    }

    public function definition(): ChannelDefinition
    {
        return new ChannelDefinition(self::KEY, 'Pushover', 'mobile', 'notifications.pushover.hint', [
            new ChannelField(
                'token',
                'notifications.pushover.token',
                FieldType::Secret,
                required: true,
                hint: 'notifications.pushover.token_hint',
                maxLength: 30,
            ),
            new ChannelField(
                'user',
                'notifications.pushover.user',
                FieldType::Secret,
                required: true,
                hint: 'notifications.pushover.user_hint',
                maxLength: 30,
            ),
            new ChannelField(
                'device',
                'notifications.pushover.device',
                FieldType::Text,
                hint: 'notifications.pushover.device_hint',
                maxLength: 100,
            ),
        ], thirdParty: true);
    }

    public function destination(ChannelSettings $settings): string
    {
        return 'https://api.pushover.net/';
    }

    public function validate(array $values, #[SensitiveParameter] array $secrets = []): array
    {
        $errors = [];
        foreach (['token', 'user'] as $field) {
            if (isset($secrets[$field]) && preg_match(self::KEY_FORMAT, $secrets[$field]) !== 1) {
                $errors[$field] = 'notifications.pushover.key_invalid';
            }
        }
        if (isset($values['device']) && $values['device'] !== '' && preg_match(self::DEVICE, $values['device']) !== 1) {
            $errors['device'] = 'notifications.pushover.device_invalid';
        }

        return $errors;
    }

    /**
     * -1, 0 or 1, never 2 (emergency).
     */
    public static function priority(Urgency $urgency): int
    {
        return match ($urgency) {
            Urgency::Low => -1,
            Urgency::Normal => 0,
            Urgency::High => 1,
        };
    }

    public function send(
        Notification $notification,
        Recipient $recipient,
        ChannelSettings $settings,
        bool $restricted,
    ): DeliveryResult {
        $token = $settings->secret('token');
        $user = $settings->secret('user');
        if ($token === null || $user === null) {
            return DeliveryResult::failed(self::KEY, ReplyWords::of('needs_setup'));
        }

        $url = $notification->url;
        $linked = $url !== null && MessageText::length($url) <= self::URL_LIMIT;
        $body = [
            'token' => $token,
            'user' => $user,
            'title' => MessageText::cut($notification->title, self::TITLE_LIMIT),
            // A link too long for `url` goes at the end of the text instead.
            'message' => MessageText::fit(
                $notification->lines(),
                self::LIMIT,
                $linked ? null : $url,
                $this->text->more($notification),
            ),
            'priority' => self::priority($notification->urgency()),
        ];
        if ($linked) {
            $body['url'] = $url;
            $body['url_title'] = MessageText::cut($this->text->openLink($notification), self::URL_TITLE_LIMIT);
        }
        $device = $settings->value('device');
        if ($device !== null) {
            $body['device'] = $device;
        }

        $answer = $this->http->send('POST', self::API . 'messages.json', ['body' => $body], $restricted);

        return ReplyWords::result(self::KEY, $answer, self::words(...));
    }

    public function verify(ChannelSettings $settings, bool $restricted): Verification
    {
        $token = $settings->secret('token');
        $user = $settings->secret('user');
        if ($token === null || $user === null) {
            return Verification::unreachable();
        }
        $body = ['token' => $token, 'user' => $user];
        $device = $settings->value('device');
        if ($device !== null) {
            $body['device'] = $device;
        }

        $url = self::API . 'users/validate.json';
        $answer = $this->http->send('POST', $url, ['body' => $body] + OutboundHttp::CHECK, $restricted);
        if ($answer->ok() && ($answer->body['status'] ?? null) === 1) {
            return Verification::works();
        }

        $words = self::words($answer);

        // A bad device or anything else is a refusal in its own words, not the keys' (bug hunt).
        return $words !== null && $answer->status !== 429 && $answer->answeredAt(400, 499)
            ? Verification::rejected($words === ReplyWords::of('pushover_keys') ? $words : ReplyWords::of('pushover_device'))
            : Verification::unreachable();
    }

    /**
     * Pushover's errors in words (spec.md §7.11); null when it worked.
     */
    public static function words(HttpAnswer $answer): ?string
    {
        if ($answer->ok()) {
            return ($answer->body['status'] ?? 1) === 1 ? null : ReplyWords::of('pushover_refused');
        }
        $errors = $answer->body['errors'] ?? [];
        $said = strtolower(implode(' ', array_filter(is_array($errors) ? $errors : [], 'is_string')));
        $keys = str_contains($said, 'token') || str_contains($said, 'user');

        return match (true) {
            $answer->status === 429 => ReplyWords::of('pushover_quota'),
            $answer->answeredAt(400, 499) && $keys => ReplyWords::of('pushover_keys'),
            $answer->answeredAt(400, 499) => ReplyWords::of('pushover_refused'),
            default => null,
        };
    }
}
