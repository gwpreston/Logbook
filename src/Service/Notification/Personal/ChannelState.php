<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Domain\Notification\ChannelRecord;
use Logbook\Domain\Notification\ChannelStatus;
use Logbook\Service\Notification\Outbound\Destination;

/**
 * A saved channel as it stands (spec.md §7.11 *Status*): its status, the
 * settings to send with when it is configured, and where it sends.
 */
final readonly class ChannelState
{
    public function __construct(
        public PersonalSender $sender,
        public ChannelRecord $record,
        public ChannelStatus $status,
        /** Null when it is not configured (*Needs setup*). */
        public ?ChannelSettings $settings,
        public ?Destination $destination,
    ) {
    }
}
