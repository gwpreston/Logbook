<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Scripts for Ask Logbook's tests (spec.md §7.26 *Tests*): OpenAI-compatible
 * chat completions that call tools or answer, queued on AiProviderMock so
 * every request still goes through the real gateway, adapter, lock and
 * usage log.
 */
final class ScriptedProvider
{
    private static int $calls = 0;

    /**
     * A turn calling one or more tools: [name, arguments] pairs.
     *
     * @param array{string, array<string, mixed>} ...$calls
     */
    public static function tools(array ...$calls): MockResponse
    {
        $toolCalls = [];
        foreach ($calls as [$name, $arguments]) {
            $toolCalls[] = [
                'id' => 'call_' . ++self::$calls,
                'type' => 'function',
                'function' => ['name' => $name, 'arguments' => json_encode((object) $arguments, JSON_THROW_ON_ERROR)],
            ];
        }

        return self::completion(['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls], 'tool_calls');
    }

    public static function answer(string $text, string $finish = 'stop'): MockResponse
    {
        return self::completion(['role' => 'assistant', 'content' => $text], $finish);
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function completion(array $message, string $finish): MockResponse
    {
        $body = [
            'id' => 'chatcmpl-' . self::$calls,
            'object' => 'chat.completion',
            'model' => 'llama3.2:3b',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ];

        return new MockResponse(
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }
}
