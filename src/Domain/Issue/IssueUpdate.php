<?php

declare(strict_types=1);

namespace Logbook\Domain\Issue;

use DateTimeImmutable;

/**
 * One line of an issue's timeline (spec.md §6 IssueUpdate): the owner's
 * note ("still knocking, worse when cold") or an automatic status change.
 */
final readonly class IssueUpdate
{
    public function __construct(
        public int $id,
        public int $issueId,
        /** Calendar date. */
        public DateTimeImmutable $notedOn,
        /** Kilometres, when the odometer was noted. */
        public ?string $odometerKm,
        public ?string $note,
        public ?IssueStatus $statusFrom,
        public ?IssueStatus $statusTo,
        public ?IssueUpdateReason $reason,
        public ?int $createdBy,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * An automatic status-change line, which cannot be edited or deleted (#316).
     */
    public function isAutomatic(): bool
    {
        return $this->statusTo !== null;
    }
}
