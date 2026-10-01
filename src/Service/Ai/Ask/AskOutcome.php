<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use Logbook\Domain\Ai\Ask\AskMessage;
use Logbook\Domain\Ai\Ask\AskThread;

/**
 * A question asked: the thread it went to, the question, and the answer
 * (or the failure, as an answer with an error code and the tool calls made
 * before it).
 */
final readonly class AskOutcome
{
    public function __construct(
        public AskThread $thread,
        public AskMessage $question,
        public AskMessage $answer,
    ) {
    }
}
