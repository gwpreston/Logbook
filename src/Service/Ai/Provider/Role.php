<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';
    /** A tool's result, answering an assistant's tool call. */
    case Tool = 'tool';
}
