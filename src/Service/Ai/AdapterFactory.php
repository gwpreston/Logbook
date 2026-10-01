<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiConnection;
use Logbook\Repository\AiSecretRepository;
use Logbook\Service\Ai\Provider\AnthropicAdapter;
use Logbook\Service\Ai\Provider\GeminiAdapter;
use Logbook\Service\Ai\Provider\HttpTransport;
use Logbook\Service\Ai\Provider\OllamaAdapter;
use Logbook\Service\Ai\Provider\OpenAiCompatibleAdapter;
use Logbook\Service\Ai\Provider\Target;
use Logbook\Support\Config\AppSettings;

/**
 * Builds a connection's adapter with its secrets opened, its key in the
 * header each API expects, and its timeout, TLS and size limit
 * (spec.md §7.25, §5 *AI adapters*).
 */
final readonly class AdapterFactory
{
    public const string API_KEY = 'api_key';
    public const string HEADER = 'header:';

    public function __construct(
        private HttpTransport $transport,
        private AiSecretRepository $secrets,
        private SecretBox $box,
        private AppSettings $settings,
    ) {
    }

    /**
     * @throws SecretUnreadable when a stored secret cannot be used
     */
    public function open(AiConnection $connection): OpenedConnection
    {
        $headers = [];
        $plain = [];
        $stored = $this->secrets->forConnection($connection->id);
        foreach ($connection->headerNames as $name) {
            $slot = self::HEADER . $name;
            if (isset($stored[$slot])) {
                $headers[$name] = $plain[] = $this->box->open($slot, $stored[$slot]);
            }
        }
        if (isset($stored[self::API_KEY])) {
            $key = $plain[] = $this->box->open(self::API_KEY, $stored[self::API_KEY]);
            $headers = self::keyHeaders($connection->adapter, $key) + $headers;
        }
        if ($connection->adapter === AdapterType::Anthropic) {
            $headers += ['anthropic-version' => AnthropicAdapter::VERSION];
        }

        $target = new Target(
            baseUrl: $connection->baseUrl,
            headers: $headers + ['User-Agent' => 'Logbook'],
            timeoutSeconds: $connection->timeoutSeconds,
            verifyTls: $connection->verifyTls || !$this->settings->ai->allowInsecureTls,
            caBundle: $connection->caBundle,
            maxRequestBytes: $connection->maxRequestBytes(),
        );

        $adapter = match ($connection->adapter) {
            AdapterType::OpenAiCompatible => new OpenAiCompatibleAdapter($this->transport, $target),
            AdapterType::Ollama => new OllamaAdapter($this->transport, $target),
            AdapterType::Anthropic => new AnthropicAdapter($this->transport, $target),
            AdapterType::Gemini => new GeminiAdapter($this->transport, $target),
        };

        return new OpenedConnection($adapter, $plain);
    }

    /**
     * @return array<string, string>
     */
    private static function keyHeaders(AdapterType $adapter, string $key): array
    {
        return match ($adapter) {
            AdapterType::Anthropic => ['x-api-key' => $key],
            // In a header, never `?key=`, so it cannot leak into a logged URL.
            AdapterType::Gemini => ['x-goog-api-key' => $key],
            default => ['Authorization' => 'Bearer ' . $key],
        };
    }
}
