<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\Recipient;
use SensitiveParameter;

/**
 * One kind of personal channel (spec.md §7.11 *Definitions*): its
 * definition and how to send with one user's settings. Registered in the
 * `notification.personal` DI list; see docs/notification-channels.md.
 */
interface PersonalSender
{
    public function definition(): ChannelDefinition;

    /**
     * The URL a send goes to, checked against the policy and shown as the
     * card's badge; null when the settings do not give one.
     */
    public function destination(ChannelSettings $settings): ?string;

    /**
     * Checks beyond each field's own (type, length, range), on the typed
     * visible values and the secrets typed (a secret left empty keeps the
     * saved one and is not here). Also run on every send against the saved
     * settings (spec.md §7.11 *Re-checked on every send*, #258).
     *
     * @param array<string, string> $values by field name
     * @param array<string, string> $secrets by field name
     * @return array<string, string> translation keys by field name
     */
    public function validate(array $values, #[SensitiveParameter] array $secrets = []): array;

    /**
     * Send one notification. Every request goes through OutboundHttp with
     * $restricted, so the policy is checked and the address pinned on
     * every send. Report failure through the result.
     */
    public function send(
        Notification $notification,
        Recipient $recipient,
        ChannelSettings $settings,
        bool $restricted,
    ): DeliveryResult;
}
