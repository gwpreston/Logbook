<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Digest;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The digest's backup line (spec.md §7.11 *The backup line*, Phase 47): for
 * an admin while the backup banner would show for them.
 */
final readonly class DigestBackup
{
    public function __construct(
        /** The last backup (UTC), or null when there has never been one. */
        public ?DateTimeImmutable $lastAt,
        /** Calendar days since it, in the admin's time zone. */
        public ?int $days,
    ) {
    }

    /**
     * The webhook JSON's `backup`.
     *
     * @return array{last_at: string|null, days: int|null, display: string}
     */
    public function toArray(string $display): array
    {
        return [
            'last_at' => $this->lastAt?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'days' => $this->days,
            'display' => $display,
        ];
    }
}
