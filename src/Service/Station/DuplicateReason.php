<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

/**
 * Why two stations may be one forecourt (spec.md §7.33 *Duplicates*).
 */
enum DuplicateReason: string
{
    /** The same brand, and the same name once the brand is taken out. */
    case SameBrand = 'same_brand';
    /** Normalised names one edit apart. */
    case OneEdit = 'one_edit';
    /** Positions within 150 m of each other. */
    case Close = 'close';
}
