<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

/**
 * Where and how to send a connection's requests: its URL, headers (the
 * key already in them), timeout, TLS and size limit. Built by AdapterFactory.
 */
final readonly class Target
{
    public function __construct(
        public string $baseUrl,
        /** @var array<string, string> */
        public array $headers = [],
        public int $timeoutSeconds = 60,
        public bool $verifyTls = true,
        public ?string $caBundle = null,
        public int $maxRequestBytes = 8 * 1024 * 1024,
    ) {
    }

    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->baseUrl,
            $headers + $this->headers,
            $this->timeoutSeconds,
            $this->verifyTls,
            $this->caBundle,
            $this->maxRequestBytes,
        );
    }

    public function withBaseUrl(string $baseUrl): self
    {
        return new self(
            $baseUrl,
            $this->headers,
            $this->timeoutSeconds,
            $this->verifyTls,
            $this->caBundle,
            $this->maxRequestBytes,
        );
    }
}
