<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Ask;

/**
 * A running question as the page polls it: the tools started so far, and
 * once done, the thread its answer went to.
 */
final readonly class AskProgress
{
    /**
     * @param list<string> $tools
     */
    public function __construct(
        public array $tools,
        public bool $done,
        public ?int $threadId,
    ) {
    }
}
