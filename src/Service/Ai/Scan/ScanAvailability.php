<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Ocr\OcrReader;

/**
 * Whether a user can scan (spec.md §7.27 *Available*): AI enabled, the
 * `ai_scan` module on, `read_document` or `read_text` with a model, and
 * the user's *Use AI features* on. Otherwise the Scan pages answer 404
 * and no entry point shows.
 */
final readonly class ScanAvailability
{
    public function __construct(
        private AiStatus $status,
        private FeatureToggles $features,
        private AiConnectionRepository $connections,
        private Router $router,
        private OcrReader $ocr,
    ) {
    }

    public function isAvailable(?User $user): bool
    {
        return $user !== null && $this->isSetUp() && $this->status->isOnFor($user);
    }

    /**
     * Set up for the install, whoever asks (the phone app's quick action).
     */
    public function isSetUp(): bool
    {
        return $this->status->isEnabled()
            && $this->features->isEnabled(Feature::AiScan)
            && ($this->status->model(AiTaskName::ReadDocument) !== null || $this->status->model(AiTaskName::ReadText) !== null);
    }

    /**
     * Whether pictures can be read: by a vision model, or through OCR and
     * a text model (spec.md §7.27 *Routing*).
     */
    public function readsPictures(): bool
    {
        return $this->router->readsPictures();
    }

    /**
     * The OCR engine photos are read by first (`tesseract`, `paddleocr`),
     * or null when a vision model reads them or nothing can.
     */
    public function picturesViaOcr(): ?string
    {
        return $this->router->picture()->ocr ? $this->ocr->engine()?->name() : null;
    }

    /**
     * Photos can't be read because *Send text, not images* is on and OCR
     * isn't working (Phase 53.3).
     */
    public function picturesTextOnly(): bool
    {
        return $this->router->picture()->problem === ScanProblem::TextOnly;
    }

    /**
     * Whether photos go as pictures checked by OCR (Phase 53.3, spec.md
     * §7.27 *The cross-check*).
     */
    public function picturesCheckedByOcr(): bool
    {
        return $this->router->picture()->crossCheck;
    }

    /**
     * The connection photos and scans go to (else the text one), to name
     * where a file is sent before it is.
     */
    public function connection(): ?AiConnection
    {
        $model = $this->status->model(AiTaskName::ReadDocument) ?? $this->status->model(AiTaskName::ReadText);

        return $model === null ? null : $this->connections->find($model->connectionId);
    }
}
