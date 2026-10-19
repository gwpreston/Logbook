<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\User\User;
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
 * reads first, so a V5C can go as text (#510). With no model reading for
 * the user (none set up, AI off, or their *Use AI features* off) and OCR
 * working, rules read it without AI (Phase 53.4, #518). A page works the
 * route out once and asks the route (K118).
 */
final readonly class Router
{
    public function __construct(
        private AiStatus $status,
        private OcrReader $ocr,
        private AiConnectionRepository $connections,
    ) {
    }

    /**
     * How $user's photos are read; with no user, the install's route for a
     * user with AI on (the phone app's quick action).
     */
    public function picture(?User $user = null): ScanRoute
    {
        if (($user !== null && !$this->status->isOnFor($user)) || ($user === null && !$this->status->isSetUp())) {
            return $this->ocr->isWorking() ? ScanRoute::rules() : ScanRoute::none(ScanProblem::NoVision);
        }
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
            return $this->ocr->isWorking() ? ScanRoute::rules() : ScanRoute::none(ScanProblem::Unassigned);
        }

        return $this->ocr->isWorking() ? ScanRoute::viaOcr($task) : ScanRoute::none(ScanProblem::NoVision);
    }

    /**
     * Whether a model reads $user's text PDFs; else rules do (Phase 53.4).
     */
    public function modelReadsText(User $user): bool
    {
        return $this->status->isOnFor($user) && $this->status->model(AiTaskName::ReadText) !== null;
    }

    /**
     * Whether photos can be read at all, by any route.
     */
    public function readsPictures(?User $user = null): bool
    {
        return $this->picture($user)->problem === null;
    }
}
