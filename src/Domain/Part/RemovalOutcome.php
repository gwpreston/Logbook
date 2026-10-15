<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

/**
 * What happened to a part when it came off (spec.md §6 PartFitting). Only
 * *Kept* leaves it on the vehicle, stored, so only *Kept* can be fitted again.
 */
enum RemovalOutcome: string
{
    case Kept = 'kept';
    case Sold = 'sold';
    case Returned = 'returned';
    case Scrapped = 'scrapped';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'part.outcome.' . $this->value;
    }

    public function status(): PartStatus
    {
        return $this === self::Kept ? PartStatus::Stored : PartStatus::Gone;
    }
}
