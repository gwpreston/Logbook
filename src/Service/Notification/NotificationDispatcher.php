<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use Logbook\Domain\Notification\ChannelRecord;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a notification through every channel that can reach the person
 * now (spec.md §7.11 *Delivery*): email, the server's webhook and their
 * usable personal channels. Works off the registry only: it never names a
 * concrete channel, so adding one never touches this class. One failing
 * channel never stops the others.
 *
 * After a real send (not a test, not the switched-off notice) each
 * channel's last result is written; a personal channel failing for the
 * fifth time in a row is switched off, and the person is told once through
 * the channels they still have.
 */
final readonly class NotificationDispatcher
{
    public function __construct(
        private ChannelRegistry $channels,
        private LoggerInterface $logger,
        private ?ChannelResults $results = null,
        private ?SwitchOffNotice $notice = null,
    ) {
    }

    public function dispatch(
        Notification $notification,
        Recipient $recipient,
        NotificationPreferences $preferences,
    ): DispatchReport {
        $results = [];
        $switchedOff = [];
        foreach ($this->channels->active($preferences, $recipient) as $channel) {
            $result = $this->send($channel, $notification, $recipient);
            $results[] = $result;
            if ($notification->kind->counts() && $this->results?->record($recipient, $result) === true) {
                $switchedOff[] = $channel->label();
                $this->logger->warning('Channel {channel} of user {user} switched off after {count} failures in a row.', [
                    'channel' => $result->channel,
                    'user' => $recipient->userId,
                    'count' => ChannelRecord::SWITCH_OFF_AFTER,
                ]);
            }
        }

        if ($switchedOff !== []) {
            $this->tellSwitchedOff($recipient, $preferences, $switchedOff);
        }

        return new DispatchReport($results);
    }

    private function send(NotificationChannel $channel, Notification $notification, Recipient $recipient): DeliveryResult
    {
        try {
            $result = $channel->send($notification, $recipient);
        } catch (Throwable $e) {
            $result = DeliveryResult::failed($channel->key(), $e->getMessage());
        }

        if ($result->delivered) {
            $this->logger->info('Notification ({kind}) sent to user {user} via {channel}.', [
                'kind' => $notification->kind->value,
                'user' => $recipient->userId,
                'channel' => $result->channel,
            ]);
        } else {
            $this->logger->warning('Notification ({kind}) to user {user} via {channel} failed: {error}', [
                'kind' => $notification->kind->value,
                'user' => $recipient->userId,
                'channel' => $result->channel,
                'error' => $result->error,
            ]);
        }

        return $result;
    }

    /**
     * Once, through the channels still usable (the switched-off ones no
     * longer are); never counted.
     *
     * @param non-empty-list<string> $labels
     */
    private function tellSwitchedOff(Recipient $recipient, NotificationPreferences $preferences, array $labels): void
    {
        $notice = $this->notice?->compose($recipient, $labels);
        if ($notice === null) {
            return;
        }
        foreach ($this->channels->active($preferences, $recipient) as $channel) {
            $this->send($channel, $notice, $recipient);
        }
    }
}
