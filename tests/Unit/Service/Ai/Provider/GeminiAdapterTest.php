<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Provider;

use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\FinishReason;
use Logbook\Service\Ai\Provider\GeminiAdapter;
use Logbook\Service\Ai\Provider\HttpTransport;
use Logbook\Service\Ai\Provider\ResponseFormat;
use Logbook\Service\Ai\Provider\Target;
use Logbook\Tests\Support\AiProviderMock;
use PHPUnit\Framework\TestCase;

/**
 * The Gemini adapter against recorded fixtures (spec.md §5 *AI adapters*).
 */
final class GeminiAdapterTest extends TestCase
{
    use AdapterAssertions;

    private AiProviderMock $mock;

    protected function setUp(): void
    {
        $this->mock = new AiProviderMock();
    }

    public function testTextJoinsPartsAndCountsThinkingAsOutputWithTheKeyInAHeader(): void
    {
        $this->mock->queue('gemini/text');
        $result = $this->adapter()->chat(new ChatRequest(
            [ChatMessage::user('Hello')],
            system: 'You are concise.',
            model: 'models/gemini-3-flash',
            temperature: 0.2,
            maxOutputTokens: 100,
        ));

        $this->assertSentFixture('gemini/text');
        $url = $this->mock->last()['url'];
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash:generateContent', $url);
        self::assertStringNotContainsString('key=', $url);
        self::assertSame('AIza-test', $this->mock->last()['headers']['x-goog-api-key']);
        self::assertSame('Hi! How can I help?', $result->text);
        self::assertSame(FinishReason::Stop, $result->finishReason);
        self::assertSame(12, $result->usage->inputTokens);
        self::assertSame(45, $result->usage->outputTokens);
    }

    public function testAFunctionCallKeepsItsThoughtSignatureAndTheResponseEchoesItsId(): void
    {
        $this->mock->queue('gemini/tool_call', 'gemini/tool_result');
        $first = $this->adapter()->chat(new ChatRequest(
            [ChatMessage::user('Add 2 and 3.')],
            tools: [self::addNumbers()],
            model: 'gemini-3-flash',
        ));
        $this->assertSentFixture('gemini/tool_call');
        self::assertSame(FinishReason::ToolCalls, $first->finishReason);
        self::assertSame('fc_1', $first->toolCalls[0]->id);
        self::assertSame(['a' => 2, 'b' => 3], $first->toolCalls[0]->arguments);

        $second = $this->adapter()->chat(new ChatRequest([
            ChatMessage::user('Add 2 and 3.'),
            $first->toMessage(),
            ChatMessage::toolResult($first->toolCalls[0], '{"sum":5}'),
        ], tools: [self::addNumbers()], model: 'gemini-3-flash'));
        $this->assertSentFixture('gemini/tool_result');
        self::assertSame('2 + 3 = 5.', $second->text);
    }

    public function testAnImageIsInlineData(): void
    {
        $this->mock->queue('gemini/image');
        $this->adapter()->chat(new ChatRequest(
            [ChatMessage::user('What colour is this image? Answer in one word.', [self::image()])],
            model: 'gemini-3-flash',
            maxOutputTokens: 64,
        ));

        $this->assertSentFixture('gemini/image');
    }

    public function testStructuredOutputUsesResponseJsonSchema(): void
    {
        $this->mock->queue('gemini/json_schema');
        $result = $this->adapter()->chat(new ChatRequest(
            [ChatMessage::user('Give the colour "red" and the count 3.')],
            response: new ResponseFormat('test_object', self::SCHEMA, JsonMode::JsonSchema),
            model: 'gemini-3-flash',
            maxOutputTokens: 256,
        ));

        $this->assertSentFixture('gemini/json_schema');
        self::assertSame(['colour' => 'red', 'count' => 3], $result->object);
    }

    public function testABlockedPromptIsFilteredNotAnError(): void
    {
        $this->mock->queue('gemini/blocked');
        $result = $this->adapter()->chat(new ChatRequest([ChatMessage::user('…')], model: 'gemini-3-flash'));

        self::assertSame(FinishReason::ContentFilter, $result->finishReason);
        self::assertSame('', $result->text);
    }

    public function testAnInvalidKeyIsAnAuthErrorThoughGeminiSays400(): void
    {
        $this->mock->queue('gemini/error_key');

        $request = new ChatRequest([ChatMessage::user('Hi')], model: 'gemini-3-flash');
        $error = $this->failure(fn () => $this->adapter()->chat($request));

        self::assertSame(ErrorCode::Auth, $error->error);
        self::assertSame(400, $error->status);
    }

    public function testModelsThatCannotGenerateAreLeftOutAndThePrefixDropped(): void
    {
        $this->mock->queue('gemini/models');
        $models = $this->adapter()->listModels();

        self::assertCount(1, $models);
        self::assertSame('gemini-3-flash', $models[0]->name);
        self::assertSame('Gemini 3 Flash', $models[0]->label);
        self::assertStringStartsWith('https://generativelanguage.googleapis.com/v1beta/models?', $this->mock->last()['url']);
    }

    private function adapter(): GeminiAdapter
    {
        return new GeminiAdapter(
            new HttpTransport($this->mock->client),
            new Target('https://generativelanguage.googleapis.com', ['x-goog-api-key' => 'AIza-test']),
        );
    }
}
