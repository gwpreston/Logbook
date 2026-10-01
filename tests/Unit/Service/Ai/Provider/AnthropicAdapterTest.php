<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Provider;

use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Service\Ai\Provider\AnthropicAdapter;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\FinishReason;
use Logbook\Service\Ai\Provider\HttpTransport;
use Logbook\Service\Ai\Provider\ResponseFormat;
use Logbook\Service\Ai\Provider\Target;
use Logbook\Tests\Support\AiProviderMock;
use PHPUnit\Framework\TestCase;

/**
 * The Anthropic Messages adapter against recorded fixtures (spec.md §5
 * *AI adapters*).
 */
final class AnthropicAdapterTest extends TestCase
{
    use AdapterAssertions;

    private AiProviderMock $mock;

    protected function setUp(): void
    {
        $this->mock = new AiProviderMock();
    }

    public function testTextSkipsThinkingAndCountsCachedInput(): void
    {
        $this->mock->queue('anthropic/text');
        $result = $this->adapter()->chat(new ChatRequest(
            [ChatMessage::user('Hello')],
            system: 'You are concise.',
            model: 'claude-sonnet-5-5',
        ));

        $this->assertSentFixture('anthropic/text');
        self::assertSame('https://api.anthropic.com/v1/messages', $this->mock->last()['url']);
        self::assertSame('sk-ant-test', $this->mock->last()['headers']['x-api-key']);
        self::assertSame('2023-06-01', $this->mock->last()['headers']['anthropic-version']);
        self::assertSame('Hi! How can I help?', $result->text);
        self::assertSame(FinishReason::Stop, $result->finishReason);
        self::assertSame(25, $result->usage->inputTokens);
        self::assertSame(12, $result->usage->outputTokens);
    }

    public function testAToolLoopSendsTheAssistantTurnBackUnchangedWithItsThinking(): void
    {
        $this->mock->queue('anthropic/tool_call', 'anthropic/tool_result');
        $first = $this->adapter()->chat(new ChatRequest(
            [ChatMessage::user('Add 2 and 3.')],
            tools: [self::addNumbers()],
            model: 'claude-sonnet-5-5',
        ));
        $this->assertSentFixture('anthropic/tool_call');
        self::assertSame(FinishReason::ToolCalls, $first->finishReason);
        self::assertSame('toolu_01A09q90qw90lq917835lq9', $first->toolCalls[0]->id);
        self::assertSame(['a' => 2, 'b' => 3], $first->toolCalls[0]->arguments);

        $second = $this->adapter()->chat(new ChatRequest([
            ChatMessage::user('Add 2 and 3.'),
            $first->toMessage(),
            ChatMessage::toolResult($first->toolCalls[0], '{"sum":5}'),
        ], tools: [self::addNumbers()], model: 'claude-sonnet-5-5'));
        $this->assertSentFixture('anthropic/tool_result');
        self::assertSame('2 + 3 = 5.', $second->text);
    }

    public function testAnImageIsABase64Block(): void
    {
        $this->mock->queue('anthropic/image');
        $result = $this->adapter()->chat(new ChatRequest(
            [ChatMessage::user('What colour is this image? Answer in one word.', [self::image()])],
            model: 'claude-sonnet-5-5',
            maxOutputTokens: 64,
        ));

        $this->assertSentFixture('anthropic/image');
        self::assertSame('Red', $result->text);
    }

    public function testStructuredOutputUsesOutputConfig(): void
    {
        $this->mock->queue('anthropic/json_schema');
        $result = $this->adapter()->chat(self::jsonRequest(JsonMode::JsonSchema, 'claude-sonnet-5-5'));

        $this->assertSentFixture('anthropic/json_schema');
        self::assertSame(['colour' => 'red', 'count' => 3], $result->object);
    }

    public function testJsonObjectIsTreatedAsStructuredOutput(): void
    {
        $this->mock->queue('anthropic/json_schema');
        $this->adapter()->chat(self::jsonRequest(JsonMode::JsonObject, 'claude-sonnet-5-5'));

        $this->assertSentFixture('anthropic/json_schema');
    }

    public function testOlderModelsGetAForcedTool(): void
    {
        $this->mock->queue('anthropic/json_tool');
        $result = $this->adapter()->chat(self::jsonRequest(JsonMode::Tool, 'claude-3-5-haiku-20241022'));

        $this->assertSentFixture('anthropic/json_tool');
        self::assertSame(['colour' => 'red', 'count' => 3], $result->object);
    }

    public function testANewestModelRefusingAForcedToolIsAProviderError(): void
    {
        $this->mock->queue('anthropic/error_forced_tool', 'anthropic/error_not_found');

        $refused = $this->failure(fn () => $this->adapter()->chat(self::jsonRequest(JsonMode::Tool, 'claude-sonnet-5-5')));
        self::assertSame(ErrorCode::Provider, $refused->error);
        self::assertStringContainsString('not supported for this model', $refused->detail);

        $missing = $this->failure(fn () => $this->adapter()->chat(self::jsonRequest(JsonMode::JsonSchema, 'claude-x')));
        self::assertSame(ErrorCode::NotFound, $missing->error);
    }

    public function testModelsArePagedAndTheirReportedCapabilitiesKept(): void
    {
        $this->mock->queue('anthropic/models_page1', 'anthropic/models_page2');
        $models = $this->adapter()->listModels();

        $names = array_map(static fn ($m): string => $m->name, $models);
        self::assertSame(['claude-sonnet-5-5', 'claude-3-5-haiku-20241022'], $names);
        self::assertSame('Claude Sonnet 5.5', $models[0]->label);
        self::assertSame([Capability::Images, Capability::Json], $models[0]->capabilities);
        self::assertSame([], $models[1]->capabilities);
        self::assertStringContainsString('after_id=claude-sonnet-5-5', $this->mock->last()['url']);
    }

    private function adapter(): AnthropicAdapter
    {
        return new AnthropicAdapter(new HttpTransport($this->mock->client), new Target('https://api.anthropic.com', [
            'x-api-key' => 'sk-ant-test',
            'anthropic-version' => AnthropicAdapter::VERSION,
        ]));
    }

    private static function jsonRequest(JsonMode $mode, string $model): ChatRequest
    {
        return new ChatRequest(
            [ChatMessage::user('Give the colour "red" and the count 3.')],
            response: new ResponseFormat('test_object', self::SCHEMA, $mode),
            model: $model,
            maxOutputTokens: 256,
        );
    }
}
