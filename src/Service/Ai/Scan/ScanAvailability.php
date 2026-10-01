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
     * Whether pictures can be read (the `read_document` model takes images).
     */
    public function readsPictures(): bool
    {
        return $this->status->model(AiTaskName::ReadDocument)?->images === true;
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
