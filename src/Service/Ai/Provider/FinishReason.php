<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

enum FinishReason: string
{
    case Stop = 'stop';
    /** Cut off at the output limit. */
    case Length = 'length';
    case ToolCalls = 'tool_calls';
    /** Refused or blocked by the provider's safety filter. */
    case ContentFilter = 'content_filter';
    case Other = 'other';
}
