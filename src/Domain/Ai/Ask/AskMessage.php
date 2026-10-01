<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Ask;

use DateTimeImmutable;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\Ai\Ask\ToolRun;

/**
 * A question or an answer in a thread (spec.md §6 AiMessage). An answer
 * keeps the tool calls behind it, the figures the grounding check could not
 * match, who answered, and the user's mark.
 */
final readonly class AskMessage
{
    /**
     * @param list<ToolRun> $toolRuns
     * @param list<string> $ungrounded
     */
    public function __construct(
        public int $id,
        public int $threadId,
        public AskRole $role,
        public string $content,
        public array $toolRuns,
        public array $ungrounded,
        public ?string $connectionName,
        public ?Location $location,
        public ?string $model,
        public ?ErrorCode $error,
        public ?FeedbackMark $feedback,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isAnswer(): bool
    {
        return $this->role === AskRole::Assistant && $this->error === null;
    }

    /**
     * The first link a tool returned: where to look when the model failed
     * after the question was understood.
     */
    public function firstLink(): ?string
    {
        foreach ($this->toolRuns as $run) {
            if ($run->result?->link !== null) {
                return $run->result->link;
            }
        }

        return null;
    }
}
