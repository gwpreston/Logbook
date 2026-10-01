<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use RuntimeException;

/**
 * Something only the user can answer before a draft can be made: which
 * grade, which document, a date Logbook can't read. The message is in the
 * user's language, for the model to ask.
 */
final class DraftQuestion extends RuntimeException
{
    /**
     * @param array<string, mixed> $extra more for the model (candidates, the word)
     */
    public function __construct(string $question, public readonly array $extra = [])
    {
        parent::__construct($question);
    }
}
