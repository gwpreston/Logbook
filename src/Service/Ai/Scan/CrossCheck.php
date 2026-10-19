<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

/**
 * What OCR's text says about a value a vision model read (spec.md §7.27
 * *The cross-check*, Phase 53.3, #508).
 */
enum CrossCheck: string
{
    /** On the page: its evidence matched, and its digits exactly. */
    case Found = 'found';
    /** Not on the page where OCR read confidently: kept, and marked. */
    case Flagged = 'flagged';
    /** Not found, but OCR was unsure there: absence proves nothing. */
    case Unchecked = 'unchecked';
}
