<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

use Logbook\Domain\Ai\ErrorCode;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * JSON over HTTP for the adapters (spec.md §5 *AI adapters*): the
 * connection's timeout, TLS and headers as per-request options, redirects
 * never followed, the size limit checked before sending, and one retry
 * only when the connection failed before any bytes were sent.
 */
final readonly class HttpTransport
{
    /** Errors raised before a request could have reached the server. */
    private const array NOT_SENT = [
        'could not resolve',
        'failed to connect',
        'connection refused',
        "couldn't connect",
        'name or service not known',
        'no route to host',
    ];

    public function __construct(private HttpClientInterface $http)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws ProviderError
     */
    public function post(Target $target, string $path, array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > $target->maxRequestBytes) {
            throw new ProviderError(ErrorCode::TooLarge, sprintf(
                'The request is %d bytes; this connection allows %d.',
                strlen($json),
                $target->maxRequestBytes,
            ));
        }

        return $this->send('POST', $target, $path, [
            'body' => $json,
            'headers' => ['Content-Type' => 'application/json'] + $target->headers,
        ]);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     * @throws ProviderError
     */
    public function get(Target $target, string $path, array $query = []): array
    {
        return $this->send('GET', $target, $path, ['query' => $query, 'headers' => $target->headers]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function send(string $method, Target $target, string $path, array $options): array
    {
        $options += [
            'timeout' => $target->timeoutSeconds,
            'max_duration' => $target->timeoutSeconds,
            'max_redirects' => 0,
            'verify_peer' => $target->verifyTls,
            'verify_host' => $target->verifyTls,
        ];
        if ($target->caBundle !== null) {
            $options['cafile'] = $target->caBundle;
        }

        $url = $target->url($path);
        for ($attempt = 1;; $attempt++) {
            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();
                $content = $response->getContent(false);
                break;
            } catch (TimeoutExceptionInterface $e) {
                throw new ProviderError(ErrorCode::Timeout, $e->getMessage());
            } catch (ExceptionInterface $e) {
                $message = $e->getMessage();
                if (stripos($message, 'timeout') !== false || stripos($message, 'timed out') !== false) {
                    throw new ProviderError(ErrorCode::Timeout, $message);
                }
                if ($attempt === 1 && self::notSent($message)) {
                    continue;
                }
                throw new ProviderError(ErrorCode::Unreachable, $message);
            }
        }

        if ($status >= 300 && $status < 400) {
            $location = $response->getHeaders(false)['location'][0] ?? '';
            throw new ProviderError(ErrorCode::Provider, sprintf(
                'HTTP %d redirect to "%s" not followed: set the base URL to where it points.',
                $status,
                $location,
            ), $status);
        }

        $decoded = json_decode($content, true);
        if ($status >= 400) {
            $message = self::providerMessage($decoded, $content, $status);
            throw new ProviderError(self::codeFor($status, $decoded), $message, $status);
        }
        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new ProviderError(ErrorCode::BadResponse, sprintf(
                'HTTP %d: the answer is not a JSON object: %s',
                $status,
                mb_substr(trim($content), 0, 200),
            ), $status);
        }

        return self::object($decoded);
    }

    private static function notSent(string $message): bool
    {
        foreach (self::NOT_SENT as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function codeFor(int $status, mixed $decoded): ErrorCode
    {
        return match (true) {
            $status === 401, $status === 403, self::isKeyInvalid($decoded) => ErrorCode::Auth,
            $status === 404 => ErrorCode::NotFound,
            $status === 408, $status === 504 => ErrorCode::Timeout,
            $status === 413 => ErrorCode::TooLarge,
            $status === 429 => ErrorCode::RateLimited,
            default => ErrorCode::Provider,
        };
    }

    /**
     * Gemini answers a bad key with 400 and `details[].reason` API_KEY_INVALID.
     */
    private static function isKeyInvalid(mixed $decoded): bool
    {
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        $details = is_array($error) && is_array($error['details'] ?? null) ? $error['details'] : [];
        foreach ($details as $detail) {
            if (is_array($detail) && ($detail['reason'] ?? null) === 'API_KEY_INVALID') {
                return true;
            }
        }

        return false;
    }

    /**
     * The provider's own message: `error.message` (OpenAI, Anthropic,
     * Gemini), `error` (Ollama, llama.cpp) or the start of the body.
     */
    public static function providerMessage(mixed $decoded, string $content, int $status): string
    {
        $message = null;
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            $message = match (true) {
                is_string($error) => $error,
                is_array($error) && is_string($error['message'] ?? null) => $error['message'],
                is_string($decoded['message'] ?? null) => $decoded['message'],
                is_string($decoded['detail'] ?? null) => $decoded['detail'],
                default => null,
            };
        }

        return sprintf('HTTP %d: %s', $status, mb_substr(trim($message ?? $content), 0, 500));
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    public static function object(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
