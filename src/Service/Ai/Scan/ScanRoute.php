<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiTaskName;

/**
 * How a photo or scanned PDF is read (spec.md §7.27 *Routing*, Phase
 * 53.2): by the vision model, by OCR and then a text model ($task names
 * whose), or not at all ($problem says why).
 */
final readonly class ScanRoute
{
    private function __construct(
        public bool $ocr,
        public ?AiTaskName $task,
        public ?ScanProblem $problem,
    ) {
    }

    public static function vision(): self
    {
        return new self(false, AiTaskName::ReadDocument, null);
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
