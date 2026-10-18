<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Ocr\OcrReader;

/**
 * Chooses how a photo or scanned PDF is read, from what is set up, with
 * no setting to learn (spec.md §7.27 *Routing*, Phase 53.2). A vision
 * model on `read_document` stays first. Without one, OCR reads the file
 * and a text model reads its text: `read_document`'s when that is a
 * text-only model, else `read_text`'s (or `ask`'s). A text PDF never
 * comes here: it always goes to `read_text`.
 */
final readonly class Router
{
    public function __construct(
        private AiStatus $status,
        private OcrReader $ocr,
    ) {
    }

    public function picture(): ScanRoute
    {
        $document = $this->status->model(AiTaskName::ReadDocument);
        if ($document?->images === true) {
            return ScanRoute::vision();
        }
        $task = match (true) {
            $document !== null => AiTaskName::ReadDocument,
            $this->status->model(AiTaskName::ReadText) !== null => AiTaskName::ReadText,
            default => null,
        };
        if ($task === null) {
            return ScanRoute::none(ScanProblem::Unassigned);
        }

        return $this->ocr->isWorking() ? ScanRoute::viaOcr($task) : ScanRoute::none(ScanProblem::NoVision);
    }

    /**
     * Whether photos can be read at all, by either route.
     */
    public function readsPictures(): bool
    {
        return $this->picture()->problem === null;
    }
}
