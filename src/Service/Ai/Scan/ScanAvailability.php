<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoRestriction;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Ocr\OcrReader;

/**
 * Whether a user can scan (spec.md §7.27 *Available*): the `scan` module
 * on, and a file can be read: by a model (`read_document` or `read_text`,
 * with the user's *Use AI features* on), or, from Phase 53.4, without AI
 * by a working OCR engine and rules, whatever AI's settings (#518, #521).
 * Never in a demo, which takes no file from a visitor (§7.36). Otherwise
 * the Scan pages answer 404 and no entry point shows.
 */
final readonly class ScanAvailability
{
    public function __construct(
        private AiStatus $status,
        private FeatureToggles $features,
        private AiConnectionRepository $connections,
        private Router $router,
        private OcrReader $ocr,
        private DemoMode $demo,
    ) {
    }

    public function isAvailable(?User $user): bool
    {
        return $user !== null
            && $this->isOpen()
            && (($this->status->isOnFor($user) && $this->hasModel()) || $this->ocr->isWorking());
    }

    /**
     * Set up for the install, whoever asks (the phone app's quick action).
     */
    public function isSetUp(): bool
    {
        return $this->isOpen()
            && (($this->status->isEnabled() && $this->hasModel()) || $this->ocr->isWorking());
    }

    /**
     * How $user's photos and scans are read (spec.md §7.27 *Routing*):
     * worked out once per page and asked below (K118).
     */
    public function route(User $user): ScanRoute
    {
        return $this->router->picture($user);
    }

    /**
     * Whether $user's text PDFs, too, are read without AI (Phase 53.4).
     */
    public function readsTextByRules(User $user): bool
    {
        return !$this->router->modelReadsText($user);
    }

    /**
     * The OCR engine photos are read by first (`tesseract`, `paddleocr`),
     * or null when a vision model reads them or nothing can.
     */
    public function picturesViaOcr(ScanRoute $route): ?string
    {
        return $route->ocr ? $this->ocr->engine()?->name() : null;
    }

    /**
     * The connection photos and scans go to (else the text one), to name
     * where a file is sent before it is; null when no model reads for the
     * user.
     */
    public function connection(?User $user = null): ?AiConnection
    {
        if ($user !== null && !$this->status->isOnFor($user)) {
            return null;
        }
        $model = $this->status->model(AiTaskName::ReadDocument) ?? $this->status->model(AiTaskName::ReadText);

        return $model === null ? null : $this->connections->find($model->connectionId);
    }

    /**
     * The module is on, and this isn't a demo (a scan takes a file).
     */
    private function isOpen(): bool
    {
        return $this->features->isEnabled(Feature::Scan) && !$this->demo->blocks(DemoRestriction::Uploads);
    }

    private function hasModel(): bool
    {
        return $this->status->model(AiTaskName::ReadDocument) !== null || $this->status->model(AiTaskName::ReadText) !== null;
    }
}
