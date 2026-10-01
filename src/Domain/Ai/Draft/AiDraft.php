<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Draft;

use DateTimeImmutable;

/**
 * A drafted entry waiting for its card's *Add* (spec.md §6 AiDraft). Once
 * added it keeps the entry it wrote, for *Undo*.
 */
final readonly class AiDraft
{
    /** How long a draft waits for *Add*. */
    public const int TTL_SECONDS = 3600;
    /** How long *Undo* is offered after *Add*. */
    public const int UNDO_SECONDS = 10;

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $card
     * @param array<string, string> $formValues
     */
    public function __construct(
        public int $id,
        public int $userId,
        public ?int $threadId,
        public DraftKind $kind,
        public int $vehicleId,
        public array $input,
        public array $card,
        public array $formValues,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $discardedAt = null,
        public ?DateTimeImmutable $appliedAt = null,
        public ?int $appliedEntryId = null,
        public ?DateTimeImmutable $appliedUpdatedAt = null,
    ) {
    }

    public function state(DateTimeImmutable $now): DraftState
    {
        return match (true) {
            $this->appliedAt !== null && $this->discardedAt !== null => DraftState::Undone,
            $this->appliedAt !== null => DraftState::Added,
            $this->discardedAt !== null => DraftState::Discarded,
            $now >= $this->expiresAt => DraftState::Expired,
            default => DraftState::Waiting,
        };
    }

    /**
     * Whether *Undo* is still offered: added, by this card, in the last
     * few seconds. Whether the entry is untouched is checked against it.
     */
    public function canUndo(DateTimeImmutable $now): bool
    {
        $applied = $this->appliedAt;

        return $applied !== null
            && $this->state($now) === DraftState::Added
            && $this->appliedEntryId !== null
            && $now->getTimestamp() - $applied->getTimestamp() <= self::UNDO_SECONDS;
    }
}
