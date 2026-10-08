<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueUpdate;

/**
 * An issue with its timeline and fixes, as the API reads it: what its
 * `ETag` is taken of, so adding a note or linking a record changes the tag
 * though the issue's own row may not (spec.md §7.20 *Issues*).
 */
final readonly class IssueState
{
    /** Who logged it, for EntryAccess (ApiEditor reads it). */
    public ?int $createdBy;

    /**
     * @param list<IssueUpdate> $updates oldest first
     * @param list<int> $fixedBy the current fixes' service record ids
     */
    public function __construct(
        public Issue $issue,
        public array $updates,
        public array $fixedBy,
    ) {
        $this->createdBy = $issue->createdBy;
    }
}
