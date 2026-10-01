<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Ai\AiModel;
use Logbook\Domain\Ai\AiTaskAssignment;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\User\User;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiModelRepository;
use Logbook\Repository\AiTaskRepository;
use Logbook\Support\Config\AppSettings;

/**
 * Whether AI is set up (spec.md §7.25): `AI_ENABLED`, and a task assigned
 * to an added model, with what the task needs, on an enabled connection.
 * Until then Logbook shows nothing about AI to anyone but the admins'
 * Settings → AI page, and sends nothing.
 */
final readonly class AiStatus
{
    public function __construct(
        private AppSettings $settings,
        private AiTaskRepository $tasks,
        private AiModelRepository $models,
        private AiConnectionRepository $connections,
        private AiPreferences $preferences,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->ai->enabled;
    }

    public function isSetUp(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        foreach (AiTaskName::cases() as $task) {
            if ($this->model($task) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user sees AI at all: set up, and their switch on.
     */
    public function isOnFor(User $user): bool
    {
        return $this->isSetUp() && $this->preferences->isOn($user->id);
    }

    /**
     * The model doing a task, or null when the task is off: unassigned, or
     * its model is no longer added, lacks what the task needs, or sits on a
     * disabled connection. `read_text` without its own model uses `ask`'s
     * when that has JSON output.
     */
    public function model(AiTaskName $task): ?AiModel
    {
        $assignment = $this->assignment($task);
        if ($assignment === null) {
            return null;
        }
        $model = $this->models->find($assignment->modelId);
        if ($model === null || !$model->added || !$task->accepts($model)) {
            return null;
        }

        return $this->connections->find($model->connectionId)?->enabled === true ? $model : null;
    }

    /**
     * The assignment a task uses (its own, or `ask`'s for `read_text`).
     */
    public function assignment(AiTaskName $task): ?AiTaskAssignment
    {
        $all = $this->tasks->all();
        $own = $all[$task->value] ?? null;
        if ($own !== null || $task !== AiTaskName::ReadText) {
            return $own;
        }

        return $all[AiTaskName::Ask->value] ?? null;
    }
}
