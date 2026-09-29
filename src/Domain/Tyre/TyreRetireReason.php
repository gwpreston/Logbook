<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

/**
 * Why a tyre was retired (spec.md §7.17). A retired tyre keeps its history
 * and lifetime figures.
 */
enum TyreRetireReason: string
{
    case Worn = 'worn';
    case Damaged = 'damaged';
    case Puncture = 'puncture';
    case Sold = 'sold';
    case Other = 'other';
}
