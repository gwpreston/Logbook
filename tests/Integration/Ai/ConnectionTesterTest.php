<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai;

use DateTimeImmutable;
use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Repository\AiBusyRepository;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiModelRepository;
use Logbook\Service\Ai\ConnectionTester;
use Logbook\Tests\Support\AiTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * *Test* (spec.md §7.25): each step's result, the capabilities it confirms
 * or clears, and the structured-output mode that works.
 */
final class ConnectionTesterTest extends AiTestCase
{
    public function testJsonFallsBackToJsonObjectAndTheModeIsRecorded(): void
    {
        $app = $this->aiApp();
        $this->pinClock($app, '2026-10-15T12:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $id = $this->network($app, 'Groq-like', 'http://10.0.0.5/v1');
        $modelId = $this->assign($app, $id, 'llama-3.3-70b-versatile', AiTaskName::ReadText, [Capability::Json]);
        $this->provider->queue(
            'groq/models',
            'openai/text',
            'openai/tool_call',
            'openai/error_json_schema_unsupported',
            'groq/json_object',
        );

        $models = $this->service($app, AiModelRepository::class);
        $model = $models->find($modelId);
        self::assertNotNull($model);
        $report = $this->service($app, ConnectionTester::class)->test($owner, $this->connectionOf($app, $id), $model);

        self::assertTrue($report->passed(), (string) json_encode($report->results));
        self::assertSame(['list', 'completion', 'tools', 'json'], array_column($report->results, 'step'));
        self::assertSame(JsonMode::JsonObject, $report->jsonMode);
        self::assertSame(2, $report->listed);
        $tested = $models->find($modelId);
        self::assertNotNull($tested);
        self::assertSame(JsonMode::JsonObject, $tested->jsonMode);
        self::assertTrue($tested->tools, 'the tool call worked, so it is confirmed');
        self::assertTrue($tested->json);
        self::assertNotNull($tested->testedAt);
        self::assertSame(['type' => 'json_object'], $this->provider->last()['json']['response_format'] ?? null);
    }

    public function testAnAnswerCutOffByThinkingSaysSo(): void
    {
        $app = $this->aiApp();
        $this->pinClock($app, '2026-10-15T12:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $id = $this->network($app, 'Box', 'http://10.0.0.5/v1');
        $modelId = $this->assign($app, $id, 'reasoner', AiTaskName::Ask, [Capability::Tools]);
        $this->provider->queue('openai/models', new MockResponse(
            '{"choices":[{"index":0,"message":{"role":"assistant","content":""},"finish_reason":"length"}]}',
            ['http_code' => 200],
        ), 'openai/tool_call');

        $model = $this->service($app, AiModelRepository::class)->find($modelId);
        self::assertNotNull($model);
        $report = $this->service($app, ConnectionTester::class)->test($owner, $this->connectionOf($app, $id), $model);

        self::assertSame('completion', $report->firstFailure()['step'] ?? null);
        self::assertStringContainsString('output limit', (string) ($report->firstFailure()['error'] ?? ''));
        self::assertSame(2048, $this->provider->requests[1]['json']['max_tokens'] ?? null, 'room for thinking');
    }

    public function testAStoppedTestKeepsWhatItDidNotTry(): void
    {
        $app = $this->aiApp();
        $this->pinClock($app, '2026-10-15T12:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $id = $this->network($app, 'Box', 'http://10.0.0.5/v1');
        $all = [Capability::Tools, Capability::Images, Capability::Json];
        $modelId = $this->assign($app, $id, 'm', AiTaskName::ReadDocument, $all);
        $now = new DateTimeImmutable('2026-10-15T12:00:00Z');
        $this->service($app, AiBusyRepository::class)->acquire($owner->id, $now, $now->modify('+5 minutes'));
        $this->provider->queue('openai/models');

        $models = $this->service($app, AiModelRepository::class);
        $model = $models->find($modelId);
        self::assertNotNull($model);
        $report = $this->service($app, ConnectionTester::class)->test($owner, $this->connectionOf($app, $id), $model);

        self::assertFalse($report->passed());
        self::assertSame(['list', 'completion'], array_column($report->results, 'step'));
        $after = $models->find($modelId);
        self::assertNotNull($after);
        self::assertTrue($after->tools && $after->images && $after->json, 'nothing it did not try is cleared');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function connectionOf(App $app, int $id): AiConnection
    {
        $connection = $this->service($app, AiConnectionRepository::class)->find($id);
        self::assertNotNull($connection);

        return $connection;
    }
}
