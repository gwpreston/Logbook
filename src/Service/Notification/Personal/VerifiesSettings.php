<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

/**
 * A kind whose token the service can check when the card is saved
 * (Telegram `getMe`, Pushover `users/validate`, Slack `auth.test`).
 */
interface VerifiesSettings
{
    /**
     * Ask the service whether these settings work, through OutboundHttp
     * with $restricted. Never throws.
     */
    public function verify(ChannelSettings $settings, bool $restricted): Verification;
}
