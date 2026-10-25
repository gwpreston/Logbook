<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai;

use DateTimeImmutable;
use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Domain\Ai\Location;
use Logbook\Repository\AiBusyRepository;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiModelRepository;
use Logbook\Service\Ai\ConnectionTester;
use Logbook\Service\Ai\Scan\ScanSchema;
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
            new MockResponse((string) json_encode(['choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"kind":"fuel_receipt","fields":{"total":{"value":"58.53","evidence":"TOTAL 58.53"}}}',
                ],
                'finish_reason' => 'stop',
            ]]]), ['http_code' => 200]),
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
        $format = $this->provider->requests[3]['json']['response_format'] ?? null;
        self::assertIsArray($format);
        $schema = $format['json_schema'] ?? null;
        self::assertIsArray($schema);
        self::assertSame('document', $schema['name'] ?? null, 'JSON is tested with the scan\'s own schema (K132)');
        self::assertSame(ScanSchema::schema(), $schema['schema'] ?? null);
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

    public function testSendTextSkipsTheImagesStepAndLeavesTheCapabilityAlone(): void
    {
        $app = $this->aiApp();
        $this->pinClock($app, '2026-10-15T12:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $id = $this->addConnection(
            $app,
            'Box',
            AdapterType::OpenAiCompatible,
            'http://10.0.0.5/v1',
            Location::Network,
            sendText: true,
        );
        $modelId = $this->assign($app, $id, 'vision-model', AiTaskName::ReadDocument, [Capability::Tools, Capability::Images]);
        $this->provider->queue('openai/models', 'openai/text', 'openai/tool_call');

        $models = $this->service($app, AiModelRepository::class);
        $model = $models->find($modelId);
        self::assertNotNull($model);
        $report = $this->service($app, ConnectionTester::class)->test($owner, $this->connectionOf($app, $id), $model);

        self::assertTrue($report->passed(), (string) json_encode($report->results));
        self::assertSame(['list', 'completion', 'tools'], array_column($report->results, 'step'));
        self::assertCount(3, $this->provider->requests, 'no image request was made');
        $after = $models->find($modelId);
        self::assertNotNull($after);
        self::assertTrue($after->images, 'the images capability is as it was');
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
