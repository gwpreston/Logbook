<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

/**
 * Where a part is now (spec.md §7.39 *Status*): replayed from its fittings
 * and stored, never edited directly.
 */
enum PartStatus: string
{
    case Fitted = 'fitted';
    case Stored = 'stored';
    case Gone = 'gone';

    public function labelKey(): string
    {
        return 'part.status.' . $this->value;
    }
}
