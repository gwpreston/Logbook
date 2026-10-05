<?php

declare(strict_types=1);

namespace Logbook\Service\Insights;

/**
 * The colour of an insight's icon tile: good news, worth a look, or plain.
 * Never the only signal: the title says the same in words.
 */
enum InsightTone: string
{
    case Good = 'good';
    case Watch = 'watch';
    case Neutral = 'neutral';
}
