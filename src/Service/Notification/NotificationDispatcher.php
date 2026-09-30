<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a notification through every channel the person has enabled and
 * that can reach them (spec.md §7.11). Works off the registry only: it
 * never names a concrete channel, so adding one never touches this class.
 * One failing channel never stops the others.
 */
final readonly class NotificationDispatcher
{
    public function __construct(
        private ChannelRegistry $channels,
        private LoggerInterface $logger,
    ) {
    }

    public function dispatch(
        Notification $notification,
        Recipient $recipient,
        NotificationPreferences $preferences,
    ): DispatchReport {
        $results = [];
        foreach ($this->channels->active($preferences, $recipient) as $channel) {
            try {
                $result = $channel->send($notification, $recipient);
            } catch (Throwable $e) {
                $result = DeliveryResult::failed($channel->key(), $e->getMessage());
            }
            $results[] = $result;

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
        }

        return new DispatchReport($results);
    }
}
