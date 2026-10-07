<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for the outbound HTTP client (ntfy, Gotify, webhooks): records
 * each request and answers with $status.
 *
 * @phpstan-type RecordedRequest array{
 *     method: string,
 *     url: string,
 *     headers: array<string, list<string>>,
 *     json: array<mixed>,
 *     resolve: array<string, string>,
 *     max_redirects: int|null,
 * }
 */
final class RecordingHttpClient
{
    /** @var list<RecordedRequest> */
    public array $requests = [];
    public int $status = 200;
    /** @var array<string, int> answers by URL prefix, over $status */
    public array $statusFor = [];
    public readonly MockHttpClient $client;

    public function __construct()
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => self::headers($options['normalized_headers'] ?? []),
                'json' => self::json($options['body'] ?? ''),
                'resolve' => self::resolve($options['resolve'] ?? null),
                'max_redirects' => is_int($options['max_redirects'] ?? null) ? $options['max_redirects'] : null,
            ];
            $status = $this->status;
            foreach ($this->statusFor as $prefix => $code) {
                if (str_starts_with($url, $prefix)) {
                    $status = $code;
                }
            }

            return new MockResponse('{}', ['http_code' => $status]);
        });
    }

    /**
     * @return list<RecordedRequest>
     */
    public function to(string $urlPrefix): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => str_starts_with($r['url'], $urlPrefix)));
    }

    /**
     * {"x-gotify-key": ["X-Gotify-Key: abc"]} → {"x-gotify-key": ["abc"]}
     *
     * @return array<string, list<string>>
     */
    private static function headers(mixed $normalized): array
    {
        $headers = [];
        foreach (is_array($normalized) ? $normalized : [] as $name => $lines) {
            foreach (is_array($lines) ? $lines : [] as $line) {
                if (is_string($line)) {
                    $headers[(string) $name][] = substr($line, strpos($line, ':') + 2);
                }
            }
        }

        return $headers;
    }

    /**
     * The `resolve` option: host => the address it was pinned to.
     *
     * @return array<string, string>
     */
    private static function resolve(mixed $option): array
    {
        $pinned = [];
        foreach (is_array($option) ? $option : [] as $host => $address) {
            if (is_string($address)) {
                $pinned[(string) $host] = $address;
            }
        }

        return $pinned;
    }

    /**
     * @return array<mixed>
     */
    private static function json(mixed $body): array
    {
        $decoded = is_string($body) ? json_decode($body, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
