<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Outbound;

/**
 * What a service answered (OutboundHttp::send): the status and the decoded
 * JSON body, or why there is none. The error never contains the request's
 * URL, which may carry a token (spec.md §7.11 *Redaction*).
 */
final readonly class HttpAnswer
{
    /**
     * @param array<array-key, mixed> $body the decoded JSON object, or [] when there is none
     */
    private function __construct(
        public ?int $status,
        public array $body = [],
        /** Seconds from a 429's Retry-After header, if any. */
        public ?int $retryAfter = null,
        /** Why no answer came: refused before any request, or a connection error. */
        public ?string $error = null,
        public bool $refused = false,
    ) {
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function answered(int $status, array $body = [], ?int $retryAfter = null): self
    {
        return new self($status, $body, $retryAfter);
    }

    public static function refused(string $error): self
    {
        return new self(null, error: $error, refused: true);
    }

    public static function unreachable(string $error): self
    {
        return new self(null, error: $error);
    }

    public function answeredAt(int $from, int $to): bool
    {
        return $this->status !== null && $this->status >= $from && $this->status <= $to;
    }

    public function ok(): bool
    {
        return $this->answeredAt(200, 299);
    }

    public function string(string $key): ?string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
