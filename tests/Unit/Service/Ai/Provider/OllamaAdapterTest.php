<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Provider;

use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\HttpTransport;
use Logbook\Service\Ai\Provider\OllamaAdapter;
use Logbook\Service\Ai\Provider\ResponseFormat;
use Logbook\Service\Ai\Provider\Target;
use Logbook\Tests\Support\AiProviderMock;
use PHPUnit\Framework\TestCase;

/**
 * Ollama against recorded fixtures (spec.md §5 *AI adapters*): chat on
 * `/v1`, models from `/api/tags` and `/api/show`.
 */
final class OllamaAdapterTest extends TestCase
{
    use AdapterAssertions;

    private AiProviderMock $mock;

    protected function setUp(): void
    {
        $this->mock = new AiProviderMock();
    }

    public function testChatGoesToTheOpenAiCompatibleEndpoint(): void
    {
        $this->mock->queue('ollama/chat');
        $result = $this->adapter('http://192.168.1.20:11434')->chat(new ChatRequest(
            [ChatMessage::user('Reply with the single word OK.')],
            model: 'llama3.2:3b',
            maxOutputTokens: 64,
        ));

        $this->assertSentFixture('ollama/chat');
        self::assertSame('http://192.168.1.20:11434/v1/chat/completions', $this->mock->last()['url']);
        self::assertSame('OK', $result->text);
    }

    public function testABaseUrlTypedWithV1StillWorks(): void
    {
        $this->mock->queue('ollama/chat');
        $this->adapter('http://localhost:11434/v1/')->chat(new ChatRequest(
            [ChatMessage::user('Reply with the single word OK.')],
            model: 'llama3.2:3b',
            maxOutputTokens: 64,
        ));

        self::assertSame('http://localhost:11434/v1/chat/completions', $this->mock->last()['url']);
    }

    public function testStructuredOutputIsAJsonSchemaResponseFormat(): void
    {
        $this->mock->queue('openai/json_schema');
        $result = $this->adapter('http://localhost:11434')->chat(new ChatRequest(
            [ChatMessage::user('Give the colour "red" and the count 3.')],
            response: new ResponseFormat('test_object', self::SCHEMA, JsonMode::JsonSchema),
            model: 'llama3.2:3b',
        ));

        self::assertSame(
            ['type' => 'json_schema', 'json_schema' => ['name' => 'test_object', 'schema' => self::SCHEMA]],
            $this->mock->last()['json']['response_format'],
        );
        self::assertArrayNotHasKey('tool_choice', $this->mock->last()['json']);
        self::assertSame(['colour' => 'red', 'count' => 3], $result->object);
    }

    public function testModelsAreTheTagsWithWhatShowReports(): void
    {
        $this->mock->queue('ollama/tags', 'ollama/show_llama', 'ollama/show_gemma');
        $models = $this->adapter('http://localhost:11434')->listModels();

        self::assertSame('http://localhost:11434/api/tags', $this->mock->requests[0]['url']);
        self::assertSame('http://localhost:11434/api/show', $this->mock->requests[1]['url']);
        self::assertEquals(AiProviderMock::fixture('ollama/show_llama')['request'], $this->mock->requests[1]['json']);
        self::assertSame(['llama3.2:3b', 'gemma3:12b'], array_map(static fn ($m): string => $m->name, $models));
        self::assertSame([Capability::Tools], $models[0]->capabilities);
        self::assertSame([Capability::Images], $models[1]->capabilities);
    }

    public function testAModelThatIsNotPulledIsNotFound(): void
    {
        $this->mock->queue('ollama/error_not_found');

        $error = $this->failure(fn () => $this->adapter('http://localhost:11434')->chat(
            new ChatRequest([ChatMessage::user('Hi')], model: 'llama9'),
        ));

        self::assertSame(ErrorCode::NotFound, $error->error);
        self::assertStringContainsString("model 'llama9' not found", $error->detail);
    }

    private function adapter(string $base): OllamaAdapter
    {
        return new OllamaAdapter(new HttpTransport($this->mock->client), new Target($base));
    }
}
