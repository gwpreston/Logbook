<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Outbound\HttpAnswer;

/**
 * A service's answer in words (spec.md §7.11, Phase 36.3). The words are
 * stored as a translation key, with the seconds to wait when there are
 * any (`notifications.reply.wait|30`), so the card shows them in the
 * reader's language; the `channel_error` Twig filter turns them back into
 * text. Neither ever contains a token.
 */
final class ReplyWords
{
    public const string PREFIX = 'notifications.reply.';

    public static function of(string $name, ?int $seconds = null): string
    {
        return self::PREFIX . $name . ($seconds === null ? '' : '|' . max(0, $seconds));
    }

    /**
     * @return array{key: string, params: array<string, int>}|null null when it is not stored words
     */
    public static function decode(string $error): ?array
    {
        if (preg_match('/^(notifications\.reply\.[a-z_]+)(?:\|(\d{1,9}))?$/', $error, $m) !== 1) {
            return null;
        }

        return ['key' => $m[1], 'params' => isset($m[2]) ? ['seconds' => (int) $m[2]] : []];
    }

    /**
     * The delivery result for an answer: refused before any request,
     * unreachable, a redirect (never followed), delivered when 2xx and the
     * service's own words are null, else those words or the status.
     *
     * @param \Closure(HttpAnswer): ?string $words the service's words for this answer, null when it is fine
     */
    public static function result(string $channel, HttpAnswer $answer, \Closure $words): DeliveryResult
    {
        if ($answer->error !== null) {
            return $answer->refused
                ? DeliveryResult::refused($channel, $answer->error)
                : DeliveryResult::failed($channel, $answer->error);
        }
        if ($answer->answeredAt(300, 399)) {
            return DeliveryResult::failed($channel, self::of('redirect'));
        }
        $said = $words($answer);
        if ($said === null && $answer->ok()) {
            return DeliveryResult::delivered($channel);
        }

        return DeliveryResult::failed($channel, $said ?? sprintf('HTTP %d', (int) $answer->status));
    }
}
