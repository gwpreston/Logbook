<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Provider;

use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\FinishReason;
use Logbook\Service\Ai\Provider\HttpTransport;
use Logbook\Service\Ai\Provider\ImageInput;
use Logbook\Service\Ai\Provider\OpenAiCompatibleAdapter;
use Logbook\Service\Ai\Provider\ProviderError;
use Logbook\Service\Ai\Provider\ResponseFormat;
use Logbook\Service\Ai\Provider\Target;
use Logbook\Tests\Support\AiProviderMock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The OpenAI-compatible adapter against recorded fixtures (spec.md §5
 * *AI adapters*): OpenAI, OpenRouter, Groq and llama.cpp.
 */
final class OpenAiCompatibleAdapterTest extends TestCase
{
    use AdapterAssertions;

    private AiProviderMock $mock;

    protected function setUp(): void
    {
        $this->mock = new AiProviderMock();
    }

    public function testTextWithASystemPromptAndOpenAisOutputLimit(): void
    {
        $this->mock->queue('openai/text');
        $result = $this->adapter()->chat(new ChatRequest(
            [ChatMessage::user('Hello')],
            system: 'You are concise.',
            model: 'gpt-5',
            temperature: 0.2,
            maxOutputTokens: 100,
        ));

        $this->assertSentFixture('openai/text');
        self::assertSame('POST', $this->mock->last()['method']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $this->mock->last()['url']);
        self::assertSame('Bearer sk-test', $this->mock->last()['headers']['authorization']);
        self::assertSame('Hi! How can I help?', $result->text);
        self::assertSame(FinishReason::Stop, $result->finishReason);
        self::assertSame(19, $result->usage->inputTokens);
        self::assertSame(7, $result->usage->outputTokens);
    }

    public function testEveryRequestCarriesTheConnectionsOptionsAndNeverFollowsRedirects(): void
    {
        $this->mock->queue('openai/text');
        $target = new Target(
            'https://api.openai.com/v1',
            ['Authorization' => 'Bearer sk-test'],
            timeoutSeconds: 45,
            verifyTls: false,
            caBundle: '/etc/ssl/lan-ca.pem',
        );
        (new OpenAiCompatibleAdapter(new HttpTransport($this->mock->client), $target))
            ->chat(new ChatRequest([ChatMessage::user('Hello')], model: 'gpt-5'));

        $options = $this->mock->last()['options'];
        self::assertSame(0, $options['max_redirects']);
        self::assertEquals(45, $options['timeout']);
        self::assertEquals(45, $options['max_duration']);
        self::assertFalse($options['verify_peer']);
        self::assertFalse($options['verify_host']);
        self::assertSame('/etc/ssl/lan-ca.pem', $options['cafile']);
    }

    public function testARedirectIsReportedNotFollowed(): void
    {
        $this->mock->queue(new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['Location' => 'https://elsewhere.example/v1'],
        ]));

        $error = $this->failure(fn () => $this->adapter()->chat(new ChatRequest([ChatMessage::user('Hi')], model: 'gpt-5')));

        self::assertSame(ErrorCode::Provider, $error->error);
        self::assertStringContainsString('not followed', $error->detail);
        self::assertStringContainsString('https://elsewhere.example/v1', $error->detail);
        self::assertCount(1, $this->mock->requests);
    }

    public function testAToolCallAndItsResultGoBackInOpenAisShape(): void
    {
        $this->mock->queue('openai/tool_call', 'openai/tool_result');
        $request = new ChatRequest([ChatMessage::user('Add 2 and 3.')], tools: [self::addNumbers()], model: 'gpt-5');

        $first = $this->adapter()->chat($request);
        $this->assertSentFixture('openai/tool_call');
        self::assertSame(FinishReason::ToolCalls, $first->finishReason);
        self::assertCount(1, $first->toolCalls);
        self::assertSame('call_abc123', $first->toolCalls[0]->id);
        self::assertSame(['a' => 2, 'b' => 3], $first->toolCalls[0]->arguments);

        $second = $this->adapter()->chat(new ChatRequest([
            ChatMessage::user('Add 2 and 3.'),
            $first->toMessage(),
            ChatMessage::toolResult($first->toolCalls[0], '{"sum":5}'),
        ], tools: [self::addNumbers()], model: 'gpt-5'));
        $this->assertSentFixture('openai/tool_result');
        self::assertSame('2 + 3 = 5.', $second->text);
    }

    public function testAnImageIsADataUri(): void
    {
        $this->mock->queue('openai/image');
        $result = $this->adapter()->chat(new ChatRequest(
            [ChatMessage::user('What colour is this image? Answer in one word.', [self::image()])],
            model: 'gpt-5',
        ));

        $this->assertSentFixture('openai/image');
        self::assertSame('Red', $result->text);
    }

    public function testJsonSchemaOutputIsCheckedAndReturnedAsAnObject(): void
    {
        $this->mock->queue('openai/json_schema');
        $result = $this->adapter()->chat(self::jsonRequest(JsonMode::JsonSchema, 'gpt-5'));

        $this->assertSentFixture('openai/json_schema');
        self::assertSame(['colour' => 'red', 'count' => 3], $result->object);
    }

    public function testJsonObjectFallbackPutsTheSchemaInTheInstructionsAndToleratesAFence(): void
    {
        $this->mock->queue('groq/json_object');
        $result = $this->adapter('https://api.groq.com/openai/v1')->chat(
            self::jsonRequest(JsonMode::JsonObject, 'llama-3.3-70b-versatile', 256),
        );

        $this->assertSentFixture('groq/json_object');
        self::assertSame(['colour' => 'red', 'count' => 3], $result->object);
    }

    public function testJsonByAForcedToolCall(): void
    {
        $this->mock->queue('openai/json_tool');
        $result = $this->adapter()->chat(self::jsonRequest(JsonMode::Tool, 'gpt-5'));

        $this->assertSentFixture('openai/json_tool');
        self::assertSame(['colour' => 'red', 'count' => 3], $result->object);
    }

    public function testAnObjectThatDoesNotFitTheSchemaIsABadResponse(): void
    {
        $this->mock->queue('openai/json_mismatch');

        $error = $this->failure(fn () => $this->adapter()->chat(self::jsonRequest(JsonMode::JsonSchema, 'gpt-5')));

        self::assertSame(ErrorCode::BadResponse, $error->error);
        self::assertStringContainsString('$.count: expected integer', $error->detail);
    }

    public function testErrorsMapToCodes(): void
    {
        $this->mock->queue('openai/error_auth', 'openai/error_rate_limit', 'openrouter/error_in_200');
        $request = new ChatRequest([ChatMessage::user('Hi')], model: 'gpt-5');

        $auth = $this->failure(fn () => $this->adapter()->chat($request));
        self::assertSame(ErrorCode::Auth, $auth->error);
        self::assertSame(401, $auth->status);
        self::assertStringContainsString('Incorrect API key', $auth->detail);

        self::assertSame(ErrorCode::RateLimited, $this->failure(fn () => $this->adapter()->chat($request))->error);

        $inside = $this->failure(fn () => $this->adapter('https://openrouter.ai/api/v1')->chat($request));
        self::assertSame(ErrorCode::Provider, $inside->error);
        self::assertStringContainsString('Provider returned error', $inside->detail);
    }

    public function testATimeoutIsATimeoutAndIsNotRetried(): void
    {
        $this->mock->queue(new MockResponse([''], [
            'error' => 'Idle timeout reached for "https://api.openai.com/v1/chat/completions".',
        ]));

        $error = $this->failure(fn () => $this->adapter()->chat(new ChatRequest([ChatMessage::user('Hi')], model: 'gpt-5')));

        self::assertSame(ErrorCode::Timeout, $error->error);
        self::assertCount(1, $this->mock->requests);
    }

    public function testAConnectionRefusedBeforeSendingIsRetriedOnce(): void
    {
        $this->mock->queue(
            new MockResponse('', ['error' => 'Failed to connect to 192.168.1.20 port 11434: Connection refused']),
            'openai/text',
        );

        $result = $this->adapter()->chat(new ChatRequest([ChatMessage::user('Hello')], model: 'gpt-5'));

        self::assertSame('Hi! How can I help?', $result->text);
        self::assertCount(2, $this->mock->requests);
    }

    public function testRefusedTwiceIsUnreachable(): void
    {
        $refused = new MockResponse('', ['error' => 'Could not resolve host: gpu.lan']);
        $this->mock->queue($refused, clone $refused);

        $error = $this->failure(fn () => $this->adapter()->chat(new ChatRequest([ChatMessage::user('Hello')], model: 'gpt-5')));

        self::assertSame(ErrorCode::Unreachable, $error->error);
        self::assertCount(2, $this->mock->requests);
    }

    public function testATooLargeRequestIsRefusedBeforeSending(): void
    {
        $target = new Target('https://api.openai.com/v1', maxRequestBytes: 1024);
        $adapter = new OpenAiCompatibleAdapter(new HttpTransport($this->mock->client), $target);

        $error = $this->failure(fn () => $adapter->chat(new ChatRequest(
            [ChatMessage::user('Read this', [new ImageInput(str_repeat('x', 2048), 'image/png')])],
            model: 'gpt-5',
        )));

        self::assertSame(ErrorCode::TooLarge, $error->error);
        self::assertSame([], $this->mock->requests);
    }

    public function testModelLists(): void
    {
        $this->mock->queue('openai/models', 'openrouter/models', 'groq/models', 'llamacpp/models');

        $openai = $this->adapter()->listModels();
        self::assertSame(['gpt-5', 'gpt-5-mini'], array_map(static fn ($m): string => $m->name, $openai));
        self::assertSame([], $openai[0]->capabilities);
        self::assertSame('https://api.openai.com/v1/models', $this->mock->last()['url']);
        self::assertSame('GET', $this->mock->last()['method']);

        $openrouter = $this->adapter('https://openrouter.ai/api/v1')->listModels();
        self::assertSame('openai/gpt-4o', $openrouter[0]->name);
        self::assertSame('OpenAI: GPT-4o', $openrouter[0]->label);
        self::assertSame([Capability::Tools, Capability::Images, Capability::Json], $openrouter[0]->capabilities);
        self::assertSame([], $openrouter[1]->capabilities);

        $groq = $this->adapter('https://api.groq.com/openai/v1')->listModels();
        self::assertSame(['llama-3.3-70b-versatile', 'gemma2-9b-it'], array_map(static fn ($m): string => $m->name, $groq));

        $llama = $this->adapter('http://localhost:8080/v1')->listModels();
        self::assertCount(1, $llama);
        self::assertSame([Capability::Images], $llama[0]->capabilities);
    }

    private function adapter(string $base = 'https://api.openai.com/v1'): OpenAiCompatibleAdapter
    {
        return new OpenAiCompatibleAdapter(
            new HttpTransport($this->mock->client),
            new Target($base, ['Authorization' => 'Bearer sk-test']),
        );
    }

    private static function jsonRequest(JsonMode $mode, string $model, ?int $max = null): ChatRequest
    {
        return new ChatRequest(
            [ChatMessage::user('Give the colour "red" and the count 3.')],
            response: new ResponseFormat('test_object', self::SCHEMA, $mode),
            model: $model,
            maxOutputTokens: $max,
        );
    }
}
