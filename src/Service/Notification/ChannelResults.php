<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use Logbook\Repository\NotificationChannelRepository;
use Logbook\Service\Notification\Personal\PersonalKinds;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Psr\Clock\ClockInterface;

/**
 * Writes a channel's last result after a real send (spec.md §7.11
 * *Delivery*): email's to the user's setting, a personal channel's to its
 * row, counting failures in a row. The server's webhook keeps none.
 */
final readonly class ChannelResults
{
    public function __construct(
        private NotificationChannelRepository $records,
        private PersonalKinds $kinds,
        private ReminderSettingsStore $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether this failure switched the channel off
     */
    public function record(Recipient $recipient, DeliveryResult $result): bool
    {
        $now = $this->clock->now();
        if ($result->channel === 'email') {
            $this->settings->recordEmailResult($recipient->userId, $result->delivered, $result->error, $now);

            return false;
        }
        if ($this->kinds->get($result->channel) === null) {
            return false;
        }
        if ($result->delivered) {
            $this->records->recordSuccess($recipient->userId, $result->channel, $now);

            return false;
        }

        return $this->records->recordFailure($recipient->userId, $result->channel, $result->error ?? 'Failed.', $now);
    }
}
