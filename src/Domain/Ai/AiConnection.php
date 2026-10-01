<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

use DateTimeImmutable;

/**
 * A connection to a model provider (spec.md §6 AiConnection, §7.25). Its
 * secrets live apart (`ai_secrets`) and never travel with it.
 */
final readonly class AiConnection
{
    public function __construct(
        public int $id,
        public string $name,
        public AdapterType $adapter,
        public string $baseUrl,
        /** The class when last saved or tested; re-checked on every call. */
        public Location $location,
        /** @var list<string> extra header names; their values are secrets */
        public array $headerNames,
        public int $timeoutSeconds,
        public bool $verifyTls,
        public ?string $caBundle,
        public int $maxRequestMb,
        public ?int $monthlyTokenCap,
        public bool $enabled,
        public ?int $acknowledgedBy,
        /** UTC instants. */
        public ?DateTimeImmutable $acknowledgedAt,
        public ?string $acknowledgedUrl,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Whether the admin has acknowledged sending data to this URL; cleared
     * by changing it.
     */
    public function isAcknowledged(): bool
    {
        return $this->acknowledgedAt !== null && $this->acknowledgedUrl === $this->baseUrl;
    }

    public function host(): string
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return is_string($host) ? trim($host, '[]') : '';
    }

    public function maxRequestBytes(): int
    {
        return $this->maxRequestMb * 1024 * 1024;
    }
}
