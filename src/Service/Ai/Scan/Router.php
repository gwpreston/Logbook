<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Location;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Ocr\OcrReader;

/**
 * Chooses how a photo or scanned PDF is read, from what is set up, with
 * no setting to learn (spec.md §7.27 *Routing*, Phase 53.2). A vision
 * model on `read_document` stays first, checked by OCR when it works
 * (Phase 53.3). Without one, OCR reads the file and a text model reads its
 * text: `read_document`'s when that is a text-only model, else
 * `read_text`'s (or `ask`'s). A vision model whose connection has *Send
 * text, not images* counts as text-only. On an *Internet* connection OCR
 * reads first, so a V5C can go as text (#510). A text PDF never comes
 * here: it always goes to `read_text`.
 */
final readonly class Router
{
    public function __construct(
        private AiStatus $status,
        private OcrReader $ocr,
        private AiConnectionRepository $connections,
    ) {
    }

    public function picture(): ScanRoute
    {
        $document = $this->status->model(AiTaskName::ReadDocument);
        if ($document?->images === true) {
            $connection = $this->connections->find($document->connectionId);
            $ocr = $this->ocr->isWorking();
            if ($connection?->sendText === true) {
                return $ocr ? ScanRoute::viaOcr(AiTaskName::ReadDocument) : ScanRoute::none(ScanProblem::TextOnly);
            }
            return ScanRoute::vision($ocr, $connection?->location === Location::Internet);
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
