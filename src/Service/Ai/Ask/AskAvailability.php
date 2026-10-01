<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Feature\FeatureToggles;

/**
 * Whether a user sees Ask Logbook (spec.md §7.26 *Where*): AI enabled, the
 * `ask` task with a model, the `ai_ask` module on, and the user's *Use AI
 * features* on. Otherwise every Ask page answers 404 and no entry point
 * shows.
 */
final readonly class AskAvailability
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
            && $this->features->isEnabled(Feature::AiAsk)
            && $this->status->model(AiTaskName::Ask) !== null;
    }

    /**
     * The connection that answers, to name where it runs before anything is asked.
     */
    public function connection(): ?AiConnection
    {
        $model = $this->status->model(AiTaskName::Ask);

        return $model === null ? null : $this->connections->find($model->connectionId);
    }
}
