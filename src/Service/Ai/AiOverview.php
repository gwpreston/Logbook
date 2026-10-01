<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiModel;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\Ai\UsageLine;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiModelRepository;
use Logbook\Repository\AiRequestRepository;
use Logbook\Support\Config\AppSettings;

/**
 * What Settings → AI shows (spec.md §7.25): connections with where they
 * run, their acknowledgement and this month's use; the task routing; and
 * one connection's models. Never a secret's value.
 */
final readonly class AiOverview
{
    /** Models shown on a connection's page at once; the search box finds the rest. */
    public const int MODEL_PAGE = 200;

    public function __construct(
        private AiConnectionRepository $connections,
        private AiModelRepository $models,
        private AiRequestRepository $requests,
        private AiStatus $status,
        private AiAdmin $admin,
        private AiGateway $gateway,
        private ConnectionLocator $locator,
        private AppSettings $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function page(): array
    {
        $since = $this->gateway->monthStart();
        /** @var array<int, UsageLine> $usage */
        $usage = [];
        foreach ($this->requests->byConnectionSince($since) as $line) {
            if (is_int($line->key)) {
                $usage[$line->key] = $line;
            }
        }

        $connections = $this->connections->listAll();
        $byId = [];
        $rows = [];
        foreach ($connections as $connection) {
            $byId[$connection->id] = $connection;
            $line = $usage[$connection->id] ?? null;
            $rows[] = [
                'connection' => $connection,
                'needs_acknowledgement' => self::needsAcknowledgement($connection),
                'secrets' => $this->admin->secretStates($connection),
                'usage' => $line,
                'paused' => $connection->monthlyTokenCap !== null && ($line?->tokens() ?? 0) >= $connection->monthlyTokenCap,
            ];
        }

        $added = $this->models->listAdded();
        $tasks = [];
        $form = [];
        foreach (AiTaskName::cases() as $task) {
            $assignment = $this->status->assignment($task);
            $model = $assignment === null ? null : $this->models->find($assignment->modelId);
            if ($assignment !== null && $assignment->task === $task) {
                $form['model_' . $task->value] = (string) $assignment->modelId;
                $form['temperature_' . $task->value] = $assignment->temperature ?? '';
                $max = $assignment->maxOutputTokens;
                $form['max_tokens_' . $task->value] = $max === null ? '' : (string) $max;
            }
            $tasks[] = [
                'task' => $task,
                'assignment' => $assignment,
                'model' => $model,
                'connection' => $model === null ? null : ($byId[$model->connectionId] ?? null),
                'own' => $assignment !== null && $assignment->task === $task,
                'active' => $this->status->model($task) !== null,
                'choices' => array_map(fn (AiModel $m): array => [
                    'model' => $m,
                    'connection' => $byId[$m->connectionId] ?? null,
                    'fits' => $task->accepts($m),
                ], $added),
            ];
        }

        return [
            'rows' => $rows,
            'tasks' => $tasks,
            'task_form' => $form,
            'task_usage' => $this->requests->byTaskSince($since),
            'month_start' => $since,
            'this_host' => implode("\n", $this->locator->thisHost()),
            'log_content' => $this->settings->ai->logContent,
            'set_up' => $this->status->isSetUp(),
            'no_session_secret' => $this->settings->sessionSecret === '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function connection(AiConnection $connection, string $search): array
    {
        $models = $this->models->listForConnection($connection->id, $search, self::MODEL_PAGE + 1);

        return [
            'connection' => $connection,
            'needs_acknowledgement' => self::needsAcknowledgement($connection),
            'gateway' => ConnectionPreset::isGateway($connection->host()),
            'secrets' => $this->admin->secretStates($connection),
            'models' => array_slice($models, 0, self::MODEL_PAGE),
            'more_models' => count($models) > self::MODEL_PAGE,
            'model_count' => $this->models->countForConnection($connection->id),
            'search' => $search,
            'usage' => $this->usage($connection),
        ];
    }

    private function usage(AiConnection $connection): ?UsageLine
    {
        foreach ($this->requests->byConnectionSince($this->gateway->monthStart()) as $line) {
            if ($line->key === $connection->id) {
                return $line;
            }
        }

        return null;
    }

    private static function needsAcknowledgement(AiConnection $connection): bool
    {
        return $connection->location === Location::Internet && !$connection->isAcknowledged();
    }
}
