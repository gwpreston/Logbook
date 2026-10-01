<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Ask;

enum AskRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
