<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Outbound;

use Logbook\Service\Notification\Channel\HttpDelivery;
use Logbook\Service\Notification\Personal\ReplyWords;
use Logbook\Service\Notification\DeliveryResult;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One POST from a personal channel (spec.md §7.11): the destination is
 * checked on every send, a member's request connects to the address that
 * was checked (pinned, and never through a proxy that would resolve the
 * name again), and redirects are never followed.
 */
final readonly class OutboundHttp
{
    /** spec.md §7.11: every channel's request gives up after 10 seconds. */
    public const int TIMEOUT = 10;
    /** A check made while someone waits (a token on saving, Find my chat): shorter. */
    public const array CHECK = ['timeout' => 5, 'max_duration' => 8];
    /** The most of an answer that is read: a service's JSON is far smaller; more is cut off. */
    public const int MAX_BODY = 65536;

    public function __construct(
        private HttpClientInterface $http,
        private OutboundDestination $destinations,
        private ?HostBreaker $breaker = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options symfony/http-client request options
     */
    public function post(string $channel, string $url, array $options, bool $restricted): DeliveryResult
    {
        // An admin's own channel is not restricted: nothing to resolve before the request.
        $destination = $this->destinations->check($url, $restricted, classify: $restricted);
        if (!$destination->isAllowed()) {
            return DeliveryResult::refused($channel, self::refusal($destination));
        }

        $options['max_redirects'] = 0;
        $options['timeout'] ??= self::TIMEOUT;
        $options['max_duration'] ??= self::TIMEOUT;
        if ($destination->address !== null) {
            $options['resolve'] = [$destination->host => $destination->address];
            $options['no_proxy'] = '*';
        }

        return HttpDelivery::post($this->http, $channel, $url, $options, $this->breaker);
    }

    /**
     * One request whose answer matters (Phase 36.3: a service's error
     * codes, `getMe`, `getUpdates`, `users/validate`, `auth.test`), with
     * the same check, pinning and no redirects as post(). A connection
     * error's text is reduced to its kind, never the URL it names.
     *
     * @param array<string, mixed> $options symfony/http-client request options
     */
    public function send(string $method, string $url, array $options, bool $restricted): HttpAnswer
    {
        $destination = $this->destinations->check($url, $restricted, classify: $restricted);
        if (!$destination->isAllowed()) {
            return HttpAnswer::refused(self::refusal($destination));
        }
        // A host that stopped answering in this run is skipped, never counted (#264).
        if ($this->breaker?->skips($url) === true) {
            return HttpAnswer::refused(ReplyWords::of('skipped_unreachable'));
        }

        $options['max_redirects'] = 0;
        $options['timeout'] ??= self::TIMEOUT;
        $options['max_duration'] ??= self::TIMEOUT;
        if ($destination->address !== null) {
            $options['resolve'] = [$destination->host => $destination->address];
            $options['no_proxy'] = '*';
        }

        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $retry = $response->getHeaders(false)['retry-after'][0] ?? null;
            // Read at most MAX_BODY: a member's server could stream without end (security review).
            $content = '';
            foreach ($this->http->stream($response) as $chunk) {
                $content .= $chunk->getContent();
                if (strlen($content) > self::MAX_BODY) {
                    $response->cancel();
                    $content = '';
                    break;
                }
            }
        } catch (ExceptionInterface $e) {
            $this->breaker?->unanswered($url);

            return HttpAnswer::unreachable(self::connectionError($e->getMessage()));
        }

        try {
            $body = $content === '' ? [] : json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = [];
        }

        return HttpAnswer::answered(
            $status,
            is_array($body) ? $body : [],
            is_string($retry) && ctype_digit($retry) ? (int) $retry : null,
        );
    }

    /**
     * A transport error in words that never quote the URL (which may
     * carry a token): a timeout, a name or connection failure, TLS.
     */
    public static function connectionError(string $message): string
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'timeout') || str_contains($lower, 'timed out') => 'The service did not answer in time.',
            str_contains($lower, 'resolve') => 'The service\'s name could not be found.',
            preg_match('/ssl|tls|certificate/', $lower) === 1 => 'A secure connection could not be made.',
            default => 'The service could not be reached.',
        };
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
