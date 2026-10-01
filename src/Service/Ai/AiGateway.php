<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\Ai\ModelInfo;
use Logbook\Domain\Ai\Outcome;
use Logbook\Domain\Ai\RequestRecord;
use Logbook\Domain\User\User;
use Logbook\Repository\AiBusyRepository;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiRequestRepository;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\ChatResult;
use Logbook\Service\Ai\Provider\ProviderError;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The only way a feature reaches a model (spec.md §5 *AI adapters*,
 * §7.25). It routes a task to its model and connection, then checks, in
 * order: AI on for the install and the user, the task assigned, the
 * connection enabled, where it runs now (an *Internet* one needs the
 * acknowledgement for its current URL), its secrets readable, the
 * monthly cap and the user's one-at-a-time lock. Only then is anything
 * sent. Every call and refusal is logged without content (unless
 * `AI_LOG_CONTENT=true`); every failure becomes an AiFailure. There is no
 * fallback to another connection.
 */
final readonly class AiGateway
{
    /** Seconds a lock outlives its connection's timeout before another request may take it. */
    public const int LOCK_GRACE = 30;

    public function __construct(
        private AppSettings $settings,
        private AiStatus $status,
        private AiPreferences $preferences,
        private AiConnectionRepository $connections,
        private AiRequestRepository $requests,
        private AiBusyRepository $busy,
        private ConnectionLocator $locator,
        private AdapterFactory $factory,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run a task's request on its model, for a user.
     *
     * @throws AiFailure
     */
    public function run(User $user, AiTaskName $task, ChatRequest $request): ChatResult
    {
        if (!$this->settings->ai->enabled || !$this->preferences->isOn($user->id)) {
            throw new AiFailure(ErrorCode::Disabled);
        }
        $model = $this->status->model($task);
        $assignment = $this->status->assignment($task);
        if ($model === null || $assignment === null) {
            $this->logRefusal($user->id, $task->value, null, '', ErrorCode::Unassigned);
            throw new AiFailure(ErrorCode::Unassigned);
        }
        $connection = $this->connections->find($model->connectionId);
        if ($connection === null) {
            throw new AiFailure(ErrorCode::Unassigned);
        }

        $request = $request->withModel(
            $model->name,
            $assignment->temperature === null ? null : (float) $assignment->temperature,
            $assignment->maxOutputTokens,
        );
        if ($request->response !== null) {
            // The mode Test found, else the adapter's best.
            $request = $request->withResponse($request->response->withMode(
                $model->jsonMode ?? JsonMode::candidates($connection->adapter)[0],
            ));
        }

        return $this->call($user, $task->value, $connection, $model->name, $request);
    }

    /**
     * Send one chat request on a connection, with every check, the lock
     * and the usage log. Used by run() and by *Test* (task `test`).
     *
     * @throws AiFailure
     */
    public function call(?User $user, string $task, AiConnection $connection, string $model, ChatRequest $request): ChatResult
    {
        $content = $this->settings->ai->logContent ? $request->transcript() : null;

        return $this->guarded(
            $user,
            $task,
            $connection,
            $model,
            static fn (OpenedConnection $opened): ChatResult => $opened->adapter->chat($request),
            static fn (ChatResult $result): array => [$result->usage->inputTokens, $result->usage->outputTokens],
            $content === null ? null : static fn (ChatResult $result): string => json_encode([
                'request' => $content,
                'response' => $result->text,
                'tool_calls' => array_map(
                    static fn (ToolCall $call): array => ['name' => $call->name, 'arguments' => $call->arguments],
                    $result->toolCalls,
                ),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * List a connection's models (Refresh models, Test). Not a model call:
     * not logged, but refused like one (acknowledgement, secrets).
     *
     * @return list<ModelInfo>
     * @throws AiFailure
     */
    public function listModels(AiConnection $connection): array
    {
        $opened = $this->prepare(null, 'list', $connection, '', false);
        try {
            return $opened->adapter->listModels();
        } catch (ProviderError $e) {
            throw $this->failure($e->error, $opened->redact($e->detail), $connection, '');
        }
    }

    /**
     * The start of this calendar month in APP_TIMEZONE, as a UTC instant:
     * when the monthly cap and Settings → AI's usage start counting.
     */
    public function monthStart(): DateTimeImmutable
    {
        $local = $this->clock->now()->setTimezone(new DateTimeZone($this->settings->timezone));

        return $local->setDate((int) $local->format('Y'), (int) $local->format('n'), 1)
            ->setTime(0, 0)
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @template T
     * @param Closure(OpenedConnection): T $send
     * @param Closure(T): array{?int, ?int} $usage
     * @param (Closure(T): string)|null $content
     * @return T
     */
    private function guarded(
        ?User $user,
        string $task,
        AiConnection $connection,
        string $model,
        Closure $send,
        Closure $usage,
        ?Closure $content,
    ): mixed {
        $opened = $this->prepare($user, $task, $connection, $model, true);

        $now = $this->clock->now();
        $expires = $now->modify(sprintf('+%d seconds', $connection->timeoutSeconds + self::LOCK_GRACE));
        if ($user !== null && !$this->busy->acquire($user->id, $now, $expires)) {
            $this->logRefusal($user->id, $task, $connection->id, $model, ErrorCode::Busy);
            throw $this->failure(ErrorCode::Busy, '', $connection, $model);
        }

        $started = hrtime(true);
        try {
            $result = $send($opened);
            [$in, $out] = $usage($result);
            $logged = $content === null ? null : $opened->redact($content($result));
            $this->log($user?->id, $task, $connection->id, $model, $started, Outcome::Ok, null, $in, $out, $logged);

            return $result;
        } catch (ProviderError $e) {
            $detail = $opened->redact($e->detail);
            $this->log($user?->id, $task, $connection->id, $model, $started, $e->error->outcome(), $e->error, null, null, null);
            $this->logger->warning('AI request failed on connection {connection} ({model}): {code} {detail}', [
                'connection' => $connection->id,
                'model' => $model,
                'code' => $e->error->value,
                'detail' => $detail,
            ]);
            throw $this->failure($e->error, $detail, $connection, $model);
        } finally {
            if ($user !== null) {
                $this->busy->release($user->id);
            }
        }
    }

    /**
     * Every check before sending; refusals of a model call are logged.
     */
    private function prepare(
        ?User $user,
        string $task,
        AiConnection $connection,
        string $model,
        bool $logRefusals,
    ): OpenedConnection {
        $refuse = function (
            ErrorCode $code,
            string $detail = '',
            ?string $variable = null,
        ) use (
            $user,
            $task,
            $connection,
            $model,
            $logRefusals,
        ): AiFailure {
            if ($logRefusals) {
                $this->logRefusal($user?->id, $task, $connection->id, $model, $code);
            }

            return $this->failure($code, $detail, $connection, $model, $variable);
        };

        if (!$this->settings->ai->enabled || !$connection->enabled) {
            throw $refuse(ErrorCode::Disabled);
        }

        $located = $this->locator->locate($connection->baseUrl);
        if ($located->location !== $connection->location) {
            $this->connections->setLocation($connection->id, $located->location);
        }
        if ($located->location === Location::Internet && !$connection->isAcknowledged()) {
            throw $refuse(ErrorCode::NotAcknowledged, sprintf('%s is on the internet and not acknowledged.', $located->host));
        }

        if (
            $connection->monthlyTokenCap !== null
            && $this->requests->tokensSince($connection->id, $this->monthStart()) >= $connection->monthlyTokenCap
        ) {
            throw $refuse(ErrorCode::CapReached);
        }

        try {
            return $this->factory->open($connection);
        } catch (SecretUnreadable $e) {
            throw $refuse(ErrorCode::SecretUnreadable, $e->getMessage(), $e->variable);
        }
    }

    private function failure(
        ErrorCode $code,
        string $detail,
        AiConnection $connection,
        string $model,
        ?string $variable = null,
    ): AiFailure {
        return new AiFailure(
            error: $code,
            detail: $detail,
            connectionName: $connection->name,
            model: $model === '' ? null : $model,
            adapter: $connection->adapter,
            timeoutSeconds: $connection->timeoutSeconds,
            variable: $variable,
        );
    }

    private function logRefusal(?int $userId, string $task, ?int $connectionId, string $model, ErrorCode $code): void
    {
        $this->log($userId, $task, $connectionId, $model, hrtime(true), Outcome::Refused, $code, null, null, null);
    }

    private function log(
        ?int $userId,
        string $task,
        ?int $connectionId,
        string $model,
        int|float $started,
        Outcome $outcome,
        ?ErrorCode $code,
        ?int $tokensIn,
        ?int $tokensOut,
        ?string $content,
    ): void {
        $this->requests->log(new RequestRecord(
            userId: $userId,
            task: $task,
            connectionId: $connectionId,
            model: $model,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            durationMs: (int) max(0, round((hrtime(true) - $started) / 1_000_000)),
            outcome: $outcome,
            errorCode: $code?->value,
            content: $content,
            createdAt: $this->clock->now(),
        ));
    }
}
