<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * A task's model and its settings (spec.md §6 AiTask).
 */
final readonly class AiTaskAssignment
{
    public function __construct(
        public AiTaskName $task,
        public int $modelId,
        /** A decimal string, 0–2, or null for the provider's default. */
        public ?string $temperature,
        public ?int $maxOutputTokens,
    ) {
    }
}
