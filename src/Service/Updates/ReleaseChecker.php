<?php

declare(strict_types=1);

namespace Logbook\Service\Updates;

use DateTimeImmutable;
use JsonException;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Version\InstalledVersion;
use Logbook\Support\Version\SemVer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Asks GitHub for the repository's latest release (spec.md §7.31): one GET
 * with the last ETag, 10 seconds and 1 MB at most, redirects followed by
 * hand and only within api.github.com, a rate limit's wait respected, and
 * only four fields read and checked. Every answer, good or bad, becomes the
 * next UpdateStatus; nothing from the response is ever run or rendered.
 */
final readonly class ReleaseChecker
{
    public const string API = 'https://api.github.com';
    public const int TIMEOUT = 10;
    public const int MAX_BYTES = 1048576;
    public const int MAX_REDIRECTS = 2;
    /** The wait after a rate limit that names none. */
    private const int DEFAULT_WAIT = 3600;
    /** A rate limit's wait is kept within a day. */
    private const int MAX_WAIT = 86400;

    public function __construct(
        private HttpClientInterface $http,
        private AppSettings $app,
        private InstalledVersion $installed,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function check(UpdateStatus $previous): UpdateStatus
    {
        $now = $this->clock->now();
        if ($previous->retryAt !== null && $previous->retryAt > $now) {
            $this->logger->info('Rate limited by GitHub until {until}; nothing sent.', [
                'until' => $previous->retryAt->format(DATE_ATOM),
            ]);

            return $previous->withError(self::rateLimited($previous->retryAt), null, $previous->retryAt);
        }

        $repo = $this->app->updateCheckRepo;
        $url = sprintf('%s/repos/%s/releases/latest', self::API, $repo);
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => sprintf('Logbook/%s (+https://github.com/%s)', $this->installed->version, $repo),
        ];
        if ($previous->etag !== null && $previous->latest !== null) {
            $headers['If-None-Match'] = $previous->etag;
        }

        try {
            for ($hops = 0;; $hops++) {
                $this->logger->info('GET {url}', ['url' => $url]);
                $response = $this->http->request('GET', $url, [
                    'headers' => $headers,
                    'timeout' => self::TIMEOUT,
                    'max_duration' => self::TIMEOUT,
                    'max_redirects' => 0,
                ]);
                $status = $response->getStatusCode();
                if (!in_array($status, [301, 302, 307, 308], true)) {
                    break;
                }
                $location = $response->getHeaders(false)['location'][0] ?? '';
                $response->cancel();
                $target = self::redirectTarget($location);
                if ($target === null || $hops >= self::MAX_REDIRECTS) {
                    return $this->failed($previous, $now, UpdateErrorCode::Redirect, ['target' => self::shown($location)]);
                }
                $url = $target;
            }

            return match (true) {
                $status === 304 => $this->unchanged($previous, $now),
                $status === 404 => $this->failed($previous, $now, UpdateErrorCode::NoReleases),
                $status === 403, $status === 429 => $this->rateLimit($previous, $now, $response, $status),
                $status === 200 => $this->release($previous, $now, $response, $repo),
                default => $this->failed($previous, $now, UpdateErrorCode::HttpStatus, ['status' => (string) $status]),
            };
        } catch (TimeoutExceptionInterface) {
            return $this->failed($previous, $now, UpdateErrorCode::Timeout);
        } catch (TransportExceptionInterface $e) {
            return $this->failed($previous, $now, UpdateErrorCode::Network, ['reason' => self::shown($e->getMessage())]);
        }
    }

    /**
     * An absolute `https://api.github.com/…` URL (or a path on it), else
     * null.
     */
    public static function redirectTarget(string $location): ?string
    {
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $location = self::API . $location;
        }
        $parts = parse_url($location);
        if (
            !is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'api.github.com'
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return $location;
    }

    private function unchanged(UpdateStatus $previous, DateTimeImmutable $now): UpdateStatus
    {
        if ($previous->latest === null) {
            return $this->failed($previous, $now, UpdateErrorCode::InvalidResponse);
        }
        $this->logger->info('Not modified since the last check.');

        return $previous->unchanged($now);
    }

    private function rateLimit(
        UpdateStatus $previous,
        DateTimeImmutable $now,
        ResponseInterface $response,
        int $status,
    ): UpdateStatus {
        $headers = $response->getHeaders(false);
        $response->cancel();
        $retryAfter = trim($headers['retry-after'][0] ?? '');
        $reset = trim($headers['x-ratelimit-reset'][0] ?? '');
        $at = match (true) {
            ctype_digit($retryAfter) => $now->getTimestamp() + (int) $retryAfter,
            ctype_digit($reset) => (int) $reset,
            default => $now->getTimestamp() + self::DEFAULT_WAIT,
        };
        $at = min(max($at, $now->getTimestamp() + 60), $now->getTimestamp() + self::MAX_WAIT);
        $until = (new DateTimeImmutable('@' . $at))->setTimezone($now->getTimezone());
        $this->logger->warning('Rate limited by GitHub ({status}) until {until}.', [
            'status' => $status,
            'until' => $until->format(DATE_ATOM),
        ]);

        return $previous->withError(self::rateLimited($until), $now, $until);
    }

    private function release(
        UpdateStatus $previous,
        DateTimeImmutable $now,
        ResponseInterface $response,
        string $repo,
    ): UpdateStatus {
        $headers = $response->getHeaders(false);
        $length = $headers['content-length'][0] ?? null;
        if ($length !== null && ctype_digit($length) && (int) $length > self::MAX_BYTES) {
            $response->cancel();

            return $this->failed($previous, $now, UpdateErrorCode::TooLarge);
        }
        $body = '';
        foreach ($this->http->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) > self::MAX_BYTES) {
                $response->cancel();

                return $this->failed($previous, $now, UpdateErrorCode::TooLarge);
            }
        }

        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (!is_array($data) || !is_string($data['tag_name'] ?? null) || !is_string($data['html_url'] ?? null)) {
            return $this->failed($previous, $now, UpdateErrorCode::InvalidResponse);
        }

        $version = SemVer::parseRelease($data['tag_name']);
        if ($version === null) {
            return $this->failed($previous, $now, UpdateErrorCode::InvalidTag, ['tag' => self::shown($data['tag_name'])]);
        }
        $url = $data['html_url'];
        if (preg_match('#^https://github\.com/([A-Za-z0-9-]+/[A-Za-z0-9._-]+)/releases/[A-Za-z0-9._~%/-]+$#D', $url, $m) !== 1) {
            return $this->failed($previous, $now, UpdateErrorCode::InvalidUrl);
        }
        if (strcasecmp($m[1], $repo) !== 0) {
            return $this->failed($previous, $now, UpdateErrorCode::Moved, ['repo' => $m[1]]);
        }

        $etag = $headers['etag'][0] ?? null;
        $this->logger->info('Latest release: {version}.', ['version' => (string) $version]);

        return $previous->withRelease(
            (string) $version,
            $url,
            self::name($data['name'] ?? null),
            self::published($data['published_at'] ?? null),
            is_string($etag) && $etag !== '' && strlen($etag) <= 200 ? $etag : null,
            $now,
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function failed(
        UpdateStatus $previous,
        DateTimeImmutable $now,
        UpdateErrorCode $code,
        array $params = [],
    ): UpdateStatus {
        $this->logger->warning('The update check failed: {code}.', ['code' => $code->value] + $params);

        return $previous->withError(new UpdateError($code, $params), $now);
    }

    private static function rateLimited(DateTimeImmutable $until): UpdateError
    {
        return new UpdateError(UpdateErrorCode::RateLimited, ['until' => $until->format(DATE_ATOM)]);
    }

    /** The release's name as plain text, at most 200 characters. */
    private static function name(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));

        return $name === '' ? null : mb_substr($name, 0, 200);
    }

    private static function published(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $value));

        return $time === false ? null : $time;
    }

    /** A value from the response, cut short for a message. */
    private static function shown(string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return mb_strlen($value) > 80 ? mb_substr($value, 0, 80) . '…' : $value;
    }
}
