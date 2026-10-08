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
 *     form: array<mixed>,
 *     body: string,
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
    /** @var array<string, string> response bodies by URL prefix (default "{}") */
    public array $bodyFor = [];
    /** @var array<string, array<string, string>> response headers by URL prefix */
    public array $headersFor = [];
    /** @var array<string, string> a transport error (its message) by URL prefix */
    public array $errorFor = [];
    /** @var (\Closure(string): void)|null called with each request's URL as it is made (a slow receiver moving the clock) */
    public ?\Closure $onRequest = null;
    public readonly MockHttpClient $client;

    public function __construct()
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            if ($this->onRequest !== null) {
                ($this->onRequest)($url);
            }
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => self::headers($options['normalized_headers'] ?? []),
                'json' => self::json($options['body'] ?? ''),
                'form' => self::form($options['body'] ?? ''),
                'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
                'resolve' => self::resolve($options['resolve'] ?? null),
                'max_redirects' => is_int($options['max_redirects'] ?? null) ? $options['max_redirects'] : null,
            ];
            $status = $this->status;
            foreach ($this->statusFor as $prefix => $code) {
                if (str_starts_with($url, $prefix)) {
                    $status = $code;
                }
            }
            $body = '{}';
            $headers = [];
            foreach ($this->bodyFor as $prefix => $text) {
                if (str_starts_with($url, $prefix)) {
                    $body = $text;
                }
            }
            foreach ($this->headersFor as $prefix => $values) {
                if (str_starts_with($url, $prefix)) {
                    $headers = $values;
                }
            }
            foreach ($this->errorFor as $prefix => $error) {
                if (str_starts_with($url, $prefix)) {
                    return new MockResponse('', ['error' => $error]);
                }
            }

            return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
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
    private static function form(mixed $body): array
    {
        if (!is_string($body) || $body === '' || $body[0] === '{') {
            return [];
        }
        parse_str($body, $fields);

        return $fields;
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
