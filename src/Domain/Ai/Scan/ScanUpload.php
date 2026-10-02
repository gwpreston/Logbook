<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Scan;

use DateTimeImmutable;

/**
 * A scanned file waiting for the entry it will belong to (spec.md §6
 * PendingUpload, `pending_uploads`). Belongs to one user only.
 */
final readonly class ScanUpload
{
    /** How long a pending upload waits for its entry. */
    public const int TTL_SECONDS = 86400;

    /**
     * @param array<string, mixed>|null $result the stored extraction, or `{"error": code}`
     * @param array<string, mixed>|null $recommendations what the saved entry's card still offers
     */
    public function __construct(
        public int $id,
        public int $userId,
        public string $token,
        public string $filename,
        public string $mime,
        public int $size,
        /** Under UPLOAD_PATH; null once the entry is saved. */
        public ?string $storedPath,
        public ?int $vehicleId,
        public ?ScanTarget $target,
        public ScanStatus $status,
        public ?int $pageCount,
        public ?array $result,
        public ?array $recommendations,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        /** The incident it was scanned for (Phase 27.2: *Update from a letter*). */
        public ?int $incidentId = null,
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /**
     * Still waiting for an entry, with its file.
     */
    public function isClaimable(DateTimeImmutable $now): bool
    {
        return $this->status->isPending() && $this->storedPath !== null && !$this->isExpired($now);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
