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
 * }
 */
final class RecordingHttpClient
{
    /** @var list<RecordedRequest> */
    public array $requests = [];
    public int $status = 200;
    public readonly MockHttpClient $client;

    public function __construct()
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => self::headers($options['normalized_headers'] ?? []),
                'json' => self::json($options['body'] ?? ''),
            ];

            return new MockResponse('{}', ['http_code' => $this->status]);
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
     * @return array<mixed>
     */
    private static function json(mixed $body): array
    {
        $decoded = is_string($body) ? json_decode($body, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
