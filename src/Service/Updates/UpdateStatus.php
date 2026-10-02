<?php

declare(strict_types=1);

namespace Logbook\Service\Updates;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * The last update check's result, stored in the global setting
 * `updates.status` (spec.md §7.31): the latest release seen, its ETag,
 * when GitHub was last asked, the last error and a rate limit's wait. An
 * error keeps the last good release, which then shows no banner.
 */
final readonly class UpdateStatus
{
    public function __construct(
        public ?string $latest = null,
        public ?string $releaseUrl = null,
        public ?string $releaseName = null,
        public ?DateTimeImmutable $publishedAt = null,
        public ?string $etag = null,
        public ?DateTimeImmutable $checkedAt = null,
        public ?UpdateError $error = null,
        public ?DateTimeImmutable $retryAt = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $error = null;
        $stored = is_array($value['error'] ?? null) ? $value['error'] : [];
        $code = is_string($stored['code'] ?? null) ? UpdateErrorCode::tryFrom($stored['code']) : null;
        if ($code !== null) {
            $params = [];
            foreach (is_array($stored['params'] ?? null) ? $stored['params'] : [] as $name => $param) {
                if (is_string($name) && is_string($param)) {
                    $params[$name] = $param;
                }
            }
            $error = new UpdateError($code, $params);
        }

        return new self(
            latest: self::string($value, 'latest'),
            releaseUrl: self::string($value, 'release_url'),
            releaseName: self::string($value, 'release_name'),
            publishedAt: self::time($value, 'published_at'),
            etag: self::string($value, 'etag'),
            checkedAt: self::time($value, 'checked_at'),
            error: $error,
            retryAt: self::time($value, 'retry_at'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'latest' => $this->latest,
            'release_url' => $this->releaseUrl,
            'release_name' => $this->releaseName,
            'published_at' => self::utc($this->publishedAt),
            'etag' => $this->etag,
            'checked_at' => self::utc($this->checkedAt),
            'error' => $this->error === null ? null : ['code' => $this->error->code->value, 'params' => $this->error->params],
            'retry_at' => self::utc($this->retryAt),
        ];
    }

    /**
     * A new release seen: the error and any wait are cleared.
     */
    public function withRelease(
        string $latest,
        string $url,
        ?string $name,
        ?DateTimeImmutable $publishedAt,
        ?string $etag,
        DateTimeImmutable $checkedAt,
    ): self {
        return new self($latest, $url, $name, $publishedAt, $etag, $checkedAt);
    }

    /** `304`: the stored release still stands. */
    public function unchanged(DateTimeImmutable $checkedAt): self
    {
        return new self(
            $this->latest,
            $this->releaseUrl,
            $this->releaseName,
            $this->publishedAt,
            $this->etag,
            $checkedAt,
        );
    }

    /**
     * GitHub was asked and the answer was an error ($checkedAt), or nothing
     * was sent (null keeps the last time). The last good release is kept.
     */
    public function withError(
        UpdateError $error,
        ?DateTimeImmutable $checkedAt,
        ?DateTimeImmutable $retryAt = null,
    ): self {
        return new self(
            $this->latest,
            $this->releaseUrl,
            $this->releaseName,
            $this->publishedAt,
            $this->etag,
            $checkedAt ?? $this->checkedAt,
            $error,
            $retryAt,
        );
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function string(array $value, string $key): ?string
    {
        $v = $value[$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function time(array $value, string $key): ?DateTimeImmutable
    {
        $v = self::string($value, $key);
        if ($v === null) {
            return null;
        }
        try {
            return new DateTimeImmutable($v);
        } catch (Exception) {
            return null;
        }
    }

    private static function utc(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
