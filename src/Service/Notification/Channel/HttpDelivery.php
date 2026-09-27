<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Channel;

use Logbook\Service\Notification\DeliveryResult;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One HTTP POST for a push/webhook channel: 2xx is delivered, anything else
 * (or a network error) a failure with a short reason.
 */
final class HttpDelivery
{
    /**
     * @param array<string, mixed> $options symfony/http-client request options
     */
    public static function post(HttpClientInterface $http, string $channel, string $url, array $options): DeliveryResult
    {
        try {
            $status = $http->request('POST', $url, $options)->getStatusCode();
        } catch (ExceptionInterface $e) {
            return DeliveryResult::failed($channel, $e->getMessage());
        }

        return $status >= 200 && $status < 300
            ? DeliveryResult::delivered($channel)
            : DeliveryResult::failed($channel, sprintf('HTTP %d', $status));
    }
}
