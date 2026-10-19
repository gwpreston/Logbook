<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiTaskName;

/**
 * How a photo or scanned PDF is read (spec.md §7.27 *Routing*, Phase
 * 53.2): by the vision model, by OCR and then a text model ($task names
 * whose), or not at all ($problem says why). From Phase 53.3 the vision
 * route is checked by OCR when it works ($crossCheck), and on an
 * *Internet* connection OCR reads first ($ocrFirst), so a registration
 * document can go as text instead. From Phase 53.4, $rules: OCR and then
 * rules read it without AI.
 */
final readonly class ScanRoute
{
    private function __construct(
        public bool $ocr,
        public ?AiTaskName $task,
        public ?ScanProblem $problem,
        public bool $crossCheck = false,
        public bool $ocrFirst = false,
        public bool $rules = false,
    ) {
    }

    /**
     * Read without AI: OCR, then rules (Phase 53.4).
     */
    public static function rules(): self
    {
        return new self(true, null, null, false, false, true);
    }

    public static function vision(bool $crossCheck = false, bool $ocrFirst = false): self
    {
        return new self(false, AiTaskName::ReadDocument, null, $crossCheck, $crossCheck && $ocrFirst);
    }

    public static function viaOcr(AiTaskName $task): self
    {
        return new self(true, $task, null);
    }

    public static function none(ScanProblem $problem): self
    {
        return new self(false, null, $problem);
    }
}
