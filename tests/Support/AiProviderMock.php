<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Kernel;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A model provider for tests (spec.md §7.25 *Tests*): answers each request
 * with the next queued recorded fixture (`tests/Fixtures/ai/<name>.json`:
 * status, the expected request body and the response body) or a raw
 * MockResponse, and records what was sent, options included, so CI needs
 * no network or model.
 *
 * @phpstan-type SentRequest array{
 *     method: string,
 *     url: string,
 *     headers: array<string, string>,
 *     json: array<mixed>,
 *     options: array<string, mixed>,
 * }
 */
final class AiProviderMock
{
    /** @var list<SentRequest> */
    public array $requests = [];

    /** @var list<MockResponse|string> */
    private array $queue = [];

    public readonly MockHttpClient $client;

    public function __construct()
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $headers = [];
            $normalized = is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
            foreach ($normalized as $name => $lines) {
                foreach (is_array($lines) ? $lines : [] as $line) {
                    if (is_string($line)) {
                        $headers[strtolower((string) $name)] = substr($line, strpos($line, ':') + 2);
                    }
                }
            }
            $recorded = [];
            foreach ($options as $key => $value) {
                $recorded[(string) $key] = $value;
            }
            $body = is_string($options['body'] ?? null) ? json_decode($options['body'], true) : null;
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'json' => is_array($body) ? $body : [],
                'options' => $recorded,
            ];
            $next = array_shift($this->queue)
                ?? throw new RuntimeException(sprintf('No response queued for %s %s.', $method, $url));
            if ($next instanceof MockResponse) {
                return $next;
            }
            $fixture = self::fixture($next);

            return new MockResponse(
                json_encode($fixture['response'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ['http_code' => $fixture['status'], 'response_headers' => ['content-type' => 'application/json']],
            );
        });
    }

    /**
     * Queue fixtures by name ("openai/text") or raw responses, in order.
     */
    public function queue(MockResponse|string ...$responses): self
    {
        $this->queue = [...$this->queue, ...array_values($responses)];

        return $this;
    }

    /**
     * @return SentRequest
     */
    public function last(): array
    {
        return $this->requests[array_key_last($this->requests) ?? throw new RuntimeException('Nothing was sent.')];
    }

    /**
     * @return array{status: int, request: array<mixed>|null, response: mixed}
     */
    public static function fixture(string $name): array
    {
        $path = Kernel::rootDir() . '/tests/Fixtures/ai/' . $name . '.json';
        $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_int($decoded['status'] ?? null)) {
            throw new RuntimeException(sprintf('Fixture %s has no status.', $name));
        }

        return [
            'status' => $decoded['status'],
            'request' => is_array($decoded['request'] ?? null) ? $decoded['request'] : null,
            'response' => $decoded['response'] ?? null,
        ];
    }
}
