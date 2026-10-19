<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Service\Ocr\OcrProblem;

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
    /** Pictures can't be read: no model that takes images, and no OCR with a text model. */
    case NoVision = 'no_vision';
    /** *Send text, not images* is on and OCR isn't working: the image is never sent instead (Phase 53.3). */
    case TextOnly = 'text_only';
    /** OCR found too little text to send (Phase 53.2). */
    case TooLittleText = 'too_little_text';
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

    /**
     * OCR's failure as a scan's (spec.md §7.27 *Reading through OCR*).
     */
    public static function fromOcr(?OcrProblem $problem): self
    {
        return match ($problem) {
            OcrProblem::Timeout => self::Timeout,
            OcrProblem::Busy => self::Busy,
            OcrProblem::TooLarge, OcrProblem::Unreadable => self::Unreadable,
            OcrProblem::Unavailable => self::NoVision,
            default => self::Failed,
        };
    }
}
