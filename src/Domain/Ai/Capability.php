<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * What a model can do beyond plain text (spec.md §7.25 *Models*). Ticked
 * by the admin, reported by some providers and confirmed by *Test*.
 */
enum Capability: string
{
    case Tools = 'tools';
    case Images = 'images';
    case Json = 'json';

    public function labelKey(): string
    {
        return 'ai.capability.' . $this->value;
    }
}
