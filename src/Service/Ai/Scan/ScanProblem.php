<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

/**
 * Why a scan gave an empty form (spec.md §7.27 *Failures*). The file is
 * attached either way.
 */
enum ScanProblem: string
{
    /** A scanned PDF with neither Ghostscript nor Imagick to render it. */
    case NoRenderer = 'no_renderer';
    /** The file could not be decoded or rendered. */
    case Unreadable = 'unreadable';
    /** The task has no model (or no model that takes images, for a picture). */
    case Unassigned = 'unassigned';
    /** The model reading pictures does not take images. */
    case NoVision = 'no_vision';
    /** The model did not answer in time. */
    case Timeout = 'timeout';
    /** Another AI request is running for this user. */
    case Busy = 'busy';
    /** Any other model or connection failure, or an answer that does not fit. */
    case Failed = 'failed';

    public function messageKey(): string
    {
        return 'scan.problem.' . $this->value;
    }
}
