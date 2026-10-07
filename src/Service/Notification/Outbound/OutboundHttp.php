<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Outbound;

use Logbook\Service\Notification\Channel\HttpDelivery;
use Logbook\Service\Notification\DeliveryResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One POST from a personal channel (spec.md §7.11): the destination is
 * checked on every send, a member's request connects to the address that
 * was checked (pinned, and never through a proxy that would resolve the
 * name again), and redirects are never followed.
 */
final readonly class OutboundHttp
{
    public function __construct(
        private HttpClientInterface $http,
        private OutboundDestination $destinations,
    ) {
    }

    /**
     * @param array<string, mixed> $options symfony/http-client request options
     */
    public function post(string $channel, string $url, array $options, bool $restricted): DeliveryResult
    {
        $destination = $this->destinations->check($url, $restricted);
        if (!$destination->isAllowed()) {
            return DeliveryResult::failed($channel, self::refusal($destination));
        }

        $options['max_redirects'] = 0;
        if ($destination->address !== null) {
            $options['resolve'] = [$destination->host => $destination->address];
            $options['no_proxy'] = '*';
        }

        return HttpDelivery::post($this->http, $channel, $url, $options);
    }

    public static function refusal(Destination $destination): string
    {
        return match ($destination->refusal) {
            Destination::BLOCKED => sprintf('%s is not an address your administrator allows.', $destination->host),
            Destination::LINK_LOCAL => sprintf('%s is a link-local or reserved address, never allowed.', $destination->host),
            Destination::UNRESOLVED => sprintf('%s could not be found.', $destination->host),
            default => 'Not an http or https address.',
        };
    }
}
