<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Closure;
use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiModel;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Domain\User\User;
use Logbook\Repository\AiModelRepository;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\ImageInput;
use Logbook\Service\Ai\Provider\ResponseFormat;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Psr\Clock\ClockInterface;

/**
 * *Test* on Settings → AI (spec.md §7.25): the model list, then for a
 * model a short completion, a tool call, a tiny image (when it is marked
 * for images) and JSON output (when marked for it, trying each mode best
 * first). Each step reports its time and its redacted error. Tool calls,
 * images and JSON are confirmed or cleared by what worked, and stored on
 * the model with the results. Every call goes through AiGateway (task
 * `test`), so the acknowledgement, limits and usage log apply.
 */
final readonly class ConnectionTester
{
    /** A 16×16 red PNG. */
    private const string IMAGE = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR42mO4oKBAEmIY1TCqYfhqAABLfxAQ'
        . 'egypMgAAAABJRU5ErkJggg==';

    private const array JSON_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'colour' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['colour', 'count'],
        'additionalProperties' => false,
    ];

    /** Errors that stop the whole test: nothing more would be sent. */
    private const array STOPPING = [
        ErrorCode::NotAcknowledged,
        ErrorCode::SecretUnreadable,
        ErrorCode::CapReached,
        ErrorCode::Busy,
        ErrorCode::Disabled,
    ];

    public function __construct(
        private AiGateway $gateway,
        private AiModelRepository $models,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Test the connection, and the model when one is given. The list's
     * models are stored as a refresh would.
     */
    public function test(User $admin, AiConnection $connection, ?AiModel $model): TestReport
    {
        $results = [];
        $listed = null;
        $stopped = false;

        $this->step($results, TestStep::List, function () use ($connection, &$listed): void {
            $models = $this->gateway->listModels($connection);
            $listed = count($models);
            $this->models->syncListed($connection->id, $models, $this->clock->now());
        }, $stopped);

        if ($model === null || $stopped) {
            return new TestReport($results, null, $listed);
        }

        $call = fn (ChatRequest $request) => $this->gateway->call($admin, 'test', $connection, $model->name, $request);
        $confirmed = [];

        $this->step($results, TestStep::Completion, static function () use ($call): void {
            $result = $call(new ChatRequest(
                [ChatMessage::user('Reply with the single word OK.')],
                maxOutputTokens: 64,
            ));
            if (trim($result->text) === '') {
                throw new AiFailure(ErrorCode::BadResponse, 'The model answered with no text.');
            }
        }, $stopped);

        $tools = static function () use ($call): void {
            $result = $call(new ChatRequest(
                [ChatMessage::user('Call the tool add_numbers to add 2 and 3.')],
                tools: [new ToolDefinition('add_numbers', 'Adds two whole numbers.', [
                    'type' => 'object',
                    'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']],
                    'required' => ['a', 'b'],
                ])],
                maxOutputTokens: 256,
            ));
            if ($result->toolCalls === [] || $result->toolCalls[0]->name !== 'add_numbers') {
                throw new AiFailure(ErrorCode::BadResponse, 'The model did not call the tool.');
            }
        };
        if (!$stopped && $this->step($results, TestStep::Tools, $tools, $stopped)) {
            $confirmed[] = Capability::Tools;
        }

        $images = static function () use ($call): void {
            $image = new ImageInput((string) base64_decode(self::IMAGE, true), 'image/png');
            $result = $call(new ChatRequest(
                [ChatMessage::user('What colour is this image? Answer in one word.', [$image])],
                maxOutputTokens: 64,
            ));
            if (trim($result->text) === '') {
                throw new AiFailure(ErrorCode::BadResponse, 'The model answered with no text.');
            }
        };
        if (!$stopped && $model->images && $this->step($results, TestStep::Images, $images, $stopped)) {
            $confirmed[] = Capability::Images;
        }

        $jsonMode = null;
        if (!$stopped && $model->json) {
            $jsonMode = $this->json($results, $call, $connection, $stopped);
            if ($jsonMode !== null) {
                $confirmed[] = Capability::Json;
            }
        }

        $this->models->recordTest($model->id, $results, $confirmed, $jsonMode, $this->clock->now());

        return new TestReport($results, $jsonMode, $listed);
    }

    /**
     * JSON output, each mode the adapter has, best first; the first that
     * gives an object fitting the schema is recorded.
     *
     * @param list<array{step: string, ok: bool, ms: int, error: ?string}> $results
     * @param Closure(ChatRequest): mixed $call
     */
    private function json(array &$results, Closure $call, AiConnection $connection, bool &$stopped): ?JsonMode
    {
        $errors = [];
        $started = hrtime(true);
        foreach (JsonMode::candidates($connection->adapter) as $mode) {
            try {
                $call(new ChatRequest(
                    [ChatMessage::user('Give the colour "red" and the count 3.')],
                    response: new ResponseFormat('test_object', self::JSON_SCHEMA, $mode),
                    maxOutputTokens: 256,
                ));
                $results[] = ['step' => TestStep::Json->value, 'ok' => true, 'ms' => self::since($started), 'error' => null];

                return $mode;
            } catch (AiFailure $e) {
                $errors[] = $mode->value . ': ' . self::describe($e);
                if (in_array($e->error, self::STOPPING, true)) {
                    $stopped = true;
                    break;
                }
            }
        }
        $results[] = [
            'step' => TestStep::Json->value,
            'ok' => false,
            'ms' => self::since($started),
            'error' => implode(' · ', $errors),
        ];

        return null;
    }

    /**
     * @param list<array{step: string, ok: bool, ms: int, error: ?string}> $results
     */
    private function step(array &$results, TestStep $step, Closure $run, bool &$stopped): bool
    {
        $started = hrtime(true);
        try {
            $run();
            $results[] = ['step' => $step->value, 'ok' => true, 'ms' => self::since($started), 'error' => null];

            return true;
        } catch (AiFailure $e) {
            $results[] = ['step' => $step->value, 'ok' => false, 'ms' => self::since($started), 'error' => self::describe($e)];
            $stopped = $stopped || in_array($e->error, self::STOPPING, true);

            return false;
        }
    }

    private static function describe(AiFailure $e): string
    {
        return $e->detail === '' ? $e->error->value : $e->detail;
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
