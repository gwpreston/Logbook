<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Insights;

/**
 * What an AI insight is about (spec.md §7.26 *AI insights*, Phase 42,
 * #358), as the model tags it: the no-repeats filter drops a `fuel_cost`
 * one for a vehicle showing *Fuel saving* and an `economy` one for a
 * vehicle showing *Economy up*. Anything else, or no tag (sets made
 * before Phase 42), is `other`.
 */
enum AiInsightTopic: string
{
    case FuelCost = 'fuel_cost';
    case Economy = 'economy';
    case Other = 'other';

    public static function read(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::Other : self::Other;
    }
}
