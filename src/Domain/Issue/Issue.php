<?php

declare(strict_types=1);

namespace Logbook\Domain\Issue;

use DateTimeImmutable;

/**
 * A fault the owner noticed and has not fixed yet, or has (spec.md §6
 * Issue, §7.37). It records the owner's words; Logbook never suggests a
 * cause.
 */
final readonly class Issue
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public IssueData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Who logged it; null = a former user. */
        public ?int $createdBy = null,
        /** Calendar date, set while fixed. */
        public ?DateTimeImmutable $fixedOn = null,
        /** The status to return to when the last fixing record is unlinked. */
        public ?IssueStatus $statusBeforeFix = null,
        public IssueSource $source = IssueSource::Manual,
        public ?string $sourceRef = null,
    ) {
    }

    public function status(): IssueStatus
    {
        return $this->data->status;
    }

    public function isFixed(): bool
    {
        return $this->data->status === IssueStatus::Fixed;
    }

    /**
     * The attachment owner this issue's files hang off, as the templates' paperclip expects.
     *
     * @return array{0: string, 1: int}
     */
    public function filesOwner(): array
    {
        return ['issue', $this->id];
    }
}
