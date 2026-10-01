<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Domain\Ai\ModelInfo;

/**
 * Anthropic's Messages API (spec.md §5 *AI adapters*): `tools`,
 * `tool_choice` and image blocks; structured output through
 * `output_config.format` (or a forced tool call for older models). The
 * base URL is `https://api.anthropic.com`.
 */
final readonly class AnthropicAdapter implements ProviderAdapter
{
    public const string VERSION = '2023-06-01';

    /** The Messages API requires a limit; this is used when the task sets none. */
    public const int DEFAULT_MAX_TOKENS = 4096;

    /** At most this many pages of 1000 models. */
    private const int MAX_PAGES = 5;

    public function __construct(
        private HttpTransport $transport,
        private Target $target,
    ) {
    }

    public function chat(ChatRequest $request): ChatResult
    {
        $format = $request->response;
        if ($format !== null && $format->mode === JsonMode::JsonObject) {
            $format = $format->withMode(JsonMode::JsonSchema);
        }

        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxOutputTokens ?? self::DEFAULT_MAX_TOKENS,
            'messages' => $this->messages($request),
        ];
        if ($request->system !== '') {
            $body['system'] = $request->system;
        }
        $tools = $request->tools;
        if ($format !== null && $format->mode === JsonMode::JsonSchema) {
            // Native structured output: the object comes back as text.
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $format->schema]];
        } elseif ($format !== null) {
            // For models before structured output; the newest refuse a forced tool.
            $tools[] = new ToolDefinition($format->name, 'Return the answer as this object.', $format->schema);
            $body['tool_choice'] = ['type' => 'tool', 'name' => $format->name];
        }
        if ($tools !== []) {
            $body['tools'] = array_map(static fn (ToolDefinition $tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->parameters === [] ? ['type' => 'object'] : $tool->parameters,
            ], $tools);
        }
        if ($request->temperature !== null) {
            $body['temperature'] = min(1.0, $request->temperature);
        }

        $result = $this->parse($this->transport->post($this->target, 'v1/messages', $body));

        return $format === null ? $result : StructuredOutput::extract($result, $format);
    }

    public function listModels(): array
    {
        $models = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['limit' => '1000'] + ($after === null ? [] : ['after_id' => $after]);
            $response = $this->transport->get($this->target, 'v1/models', $query);
            $data = $response['data'] ?? null;
            if (!is_array($data)) {
                throw new ProviderError(ErrorCode::BadResponse, 'The model list has no "data".');
            }
            foreach ($data as $item) {
                if (is_array($item) && is_string($item['id'] ?? null)) {
                    $label = $item['display_name'] ?? null;
                    $models[] = new ModelInfo($item['id'], is_string($label) ? $label : null, self::reported($item));
                }
            }
            $last = $response['last_id'] ?? null;
            if (($response['has_more'] ?? false) !== true || !is_string($last)) {
                break;
            }
            $after = $last;
        }

        return $models;
    }

    /**
     * What the list reports (`capabilities.image_input`,
     * `capabilities.structured_outputs`). It says nothing about tools.
     *
     * @param array<mixed> $item
     * @return list<Capability>
     */
    private static function reported(array $item): array
    {
        $capabilities = is_array($item['capabilities'] ?? null) ? $item['capabilities'] : [];
        $supported = static fn (string $name): bool => is_array($capabilities[$name] ?? null)
            && ($capabilities[$name]['supported'] ?? false) === true;

        return array_values(array_filter([
            $supported('image_input') ? Capability::Images : null,
            $supported('structured_outputs') ? Capability::Json : null,
        ]));
    }

    /**
     * Anthropic's turns alternate user and assistant: tool results are
     * user turns, and consecutive ones are merged. An assistant turn from
     * this API goes back exactly as it came (thinking blocks included).
     *
     * @return list<array{role: string, content: array<mixed>}> raw turns are opaque
     */
    private function messages(ChatRequest $request): array
    {
        $messages = [];
        foreach ($request->messages as $message) {
            [$role, $blocks] = match ($message->role) {
                Role::User => ['user', [
                    ...array_map(static fn (ImageInput $image): array => [
                        'type' => 'image',
                        'source' => ['type' => 'base64', 'media_type' => $image->mediaType, 'data' => $image->base64()],
                    ], $message->images),
                    ['type' => 'text', 'text' => $message->text],
                ]],
                Role::Assistant => ['assistant', $message->raw ?? [
                    ...($message->text === '' ? [] : [['type' => 'text', 'text' => $message->text]]),
                    ...array_map(static fn (ToolCall $call): array => [
                        'type' => 'tool_use',
                        'id' => $call->id,
                        'name' => $call->name,
                        'input' => (object) $call->arguments,
                    ], $message->toolCalls),
                ]],
                Role::Tool => ['user', [[
                    'type' => 'tool_result',
                    'tool_use_id' => (string) $message->toolCallId,
                    'content' => $message->text,
                ]]],
            };
            $last = array_key_last($messages);
            if ($last !== null && $messages[$last]['role'] === $role) {
                $messages[$last]['content'] = [...$messages[$last]['content'], ...$blocks];
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $blocks];
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function parse(array $response): ChatResult
    {
        $content = $response['content'] ?? null;
        if (!is_array($content)) {
            throw new ProviderError(ErrorCode::BadResponse, 'The answer has no "content".');
        }

        $text = '';
        $calls = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
            if (($block['type'] ?? null) === 'tool_use' && is_string($block['name'] ?? null)) {
                $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                $id = is_string($block['id'] ?? null) ? $block['id'] : 'call_' . count($calls);
                $calls[] = new ToolCall($id, $block['name'], HttpTransport::object($input));
            }
        }
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $count = static fn (string $key): int => is_int($usage[$key] ?? null) ? $usage[$key] : 0;

        return new ChatResult(
            text: $text,
            toolCalls: $calls,
            finishReason: match ($response['stop_reason'] ?? null) {
                'end_turn', 'stop_sequence', 'pause_turn' => FinishReason::Stop,
                'max_tokens', 'model_context_window_exceeded' => FinishReason::Length,
                'tool_use' => FinishReason::ToolCalls,
                'refusal' => FinishReason::ContentFilter,
                default => $calls === [] ? FinishReason::Other : FinishReason::ToolCalls,
            },
            usage: new Usage(
                // Cached input is still input.
                is_int($usage['input_tokens'] ?? null)
                    ? $usage['input_tokens'] + $count('cache_creation_input_tokens') + $count('cache_read_input_tokens')
                    : null,
                is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
            ),
            raw: $content,
        );
    }
}
