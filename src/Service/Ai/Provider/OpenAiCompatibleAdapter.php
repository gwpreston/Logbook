<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Domain\Ai\ModelInfo;

/**
 * OpenAI's Chat Completions API, which most runtimes and gateways speak:
 * OpenAI, OpenRouter, Groq, Mistral, Together, DeepSeek, Ollama's `/v1`,
 * llama.cpp server, LM Studio, vLLM and LocalAI (spec.md §5 *AI adapters*).
 */
final readonly class OpenAiCompatibleAdapter implements ProviderAdapter
{
    /** A tool without arguments still declares an object. */
    private const array EMPTY_OBJECT = ['type' => 'object'];

    public function __construct(
        private HttpTransport $transport,
        private Target $target,
    ) {
    }

    public function chat(ChatRequest $request): ChatResult
    {
        $body = [
            'model' => $request->model,
            'messages' => $this->messages($request),
        ];
        $tools = $request->tools;
        $format = $request->response;
        if ($format !== null && $format->mode === JsonMode::Tool) {
            $tools[] = new ToolDefinition($format->name, 'Return the answer as this object.', $format->schema);
            $body['tool_choice'] = ['type' => 'function', 'function' => ['name' => $format->name]];
        }
        if ($tools !== []) {
            $body['tools'] = array_map(static fn (ToolDefinition $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->parameters === [] ? self::EMPTY_OBJECT : $tool->parameters,
                ],
            ], $tools);
        }
        if ($format !== null && $format->mode === JsonMode::JsonSchema) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => $format->name, 'schema' => $format->schema],
            ];
        } elseif ($format !== null && $format->mode === JsonMode::JsonObject) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($request->maxOutputTokens !== null) {
            $body[$this->usesCompletionTokens() ? 'max_completion_tokens' : 'max_tokens'] = $request->maxOutputTokens;
        }

        $result = $this->parse($this->transport->post($this->target, 'chat/completions', $body));

        return $format === null ? $result : StructuredOutput::extract($result, $format);
    }

    public function listModels(): array
    {
        $response = $this->transport->get($this->target, 'models');
        $data = $response['data'] ?? $response['models'] ?? null;
        if (!is_array($data)) {
            throw new ProviderError(ErrorCode::BadResponse, 'The model list has no "data".');
        }

        $multimodal = self::multimodal($response['models'] ?? null);
        $models = [];
        foreach ($data as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)) {
                continue;
            }
            $label = $item['name'] ?? null;
            $capabilities = self::reported($item);
            if (in_array($item['id'], $multimodal, true) && !in_array(Capability::Images, $capabilities, true)) {
                $capabilities[] = Capability::Images;
            }
            $models[] = new ModelInfo(
                name: $item['id'],
                label: is_string($label) && $label !== $item['id'] ? $label : null,
                capabilities: $capabilities,
            );
        }

        return $models;
    }

    /**
     * What a gateway reports (OpenRouter: `supported_parameters` and
     * `architecture.input_modalities`); nothing for plain lists.
     *
     * @param array<mixed> $item
     * @return list<Capability>
     */
    private static function reported(array $item): array
    {
        $parameters = is_array($item['supported_parameters'] ?? null) ? $item['supported_parameters'] : [];
        $architecture = is_array($item['architecture'] ?? null) ? $item['architecture'] : [];
        $modalities = is_array($architecture['input_modalities'] ?? null) ? $architecture['input_modalities'] : [];

        $capabilities = [];
        if (in_array('tools', $parameters, true)) {
            $capabilities[] = Capability::Tools;
        }
        if (in_array('image', $modalities, true)) {
            $capabilities[] = Capability::Images;
        }
        if (in_array('structured_outputs', $parameters, true) || in_array('response_format', $parameters, true)) {
            $capabilities[] = Capability::Json;
        }

        return $capabilities;
    }

    /**
     * llama.cpp lists an Ollama-style `models` array beside `data`, whose
     * `capabilities` hold `multimodal` when a vision projector is loaded.
     *
     * @return list<string> the names with images
     */
    private static function multimodal(mixed $models): array
    {
        $names = [];
        foreach (is_array($models) ? $models : [] as $model) {
            if (
                is_array($model) && is_string($model['model'] ?? null)
                && in_array('multimodal', is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [], true)
            ) {
                $names[] = $model['model'];
            }
        }

        return $names;
    }

    /**
     * OpenAI itself replaced `max_tokens` with `max_completion_tokens`
     * (its reasoning models refuse the old name); every other server
     * still reads `max_tokens`.
     */
    private function usesCompletionTokens(): bool
    {
        return parse_url($this->target->baseUrl, PHP_URL_HOST) === 'api.openai.com';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(ChatRequest $request): array
    {
        $system = $request->system;
        if ($request->response?->mode === JsonMode::JsonObject) {
            $system = trim($system . "\n\n" . $request->response->instruction());
        }
        $messages = $system === '' ? [] : [['role' => 'system', 'content' => $system]];

        foreach ($request->messages as $message) {
            $messages[] = match ($message->role) {
                Role::User => [
                    'role' => 'user',
                    'content' => $message->images === [] ? $message->text : [
                        ['type' => 'text', 'text' => $message->text],
                        ...array_map(static fn (ImageInput $image): array => [
                            'type' => 'image_url',
                            'image_url' => ['url' => $image->dataUri()],
                        ], $message->images),
                    ],
                ],
                Role::Assistant => ['role' => 'assistant', 'content' => $message->text === '' ? null : $message->text]
                    + ($message->toolCalls === [] ? [] : ['tool_calls' => array_map(static fn (ToolCall $call): array => [
                        'id' => $call->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $call->name,
                            'arguments' => json_encode((object) $call->arguments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        ],
                    ], $message->toolCalls)]),
                Role::Tool => ['role' => 'tool', 'tool_call_id' => (string) $message->toolCallId, 'content' => $message->text],
            };
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function parse(array $response): ChatResult
    {
        // OpenRouter can report a failure inside an HTTP 200.
        if (isset($response['error'])) {
            throw new ProviderError(ErrorCode::Provider, HttpTransport::providerMessage($response, '', 200), 200);
        }
        $choices = $response['choices'] ?? null;
        $choice = is_array($choices) && is_array($choices[0] ?? null) ? $choices[0] : null;
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : null;
        if ($choice === null || $message === null) {
            throw new ProviderError(ErrorCode::BadResponse, 'The answer has no "choices[0].message".');
        }

        if (($choice['finish_reason'] ?? null) === 'error') {
            throw new ProviderError(ErrorCode::Provider, HttpTransport::providerMessage($choice, '', 200), 200);
        }

        $calls = [];
        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $i => $call) {
            if (!is_array($call) || !is_array($call['function'] ?? null) || !is_string($call['function']['name'] ?? null)) {
                continue;
            }
            $function = $call['function'];
            $arguments = $function['arguments'] ?? '{}';
            $decoded = is_array($arguments) ? $arguments : json_decode(is_string($arguments) ? $arguments : '', true);
            if (!is_array($decoded)) {
                throw new ProviderError(
                    ErrorCode::BadResponse,
                    sprintf('The arguments of "%s" are not JSON.', $function['name']),
                );
            }
            $id = is_string($call['id'] ?? null) && $call['id'] !== '' ? $call['id'] : 'call_' . $i;
            $calls[] = new ToolCall($id, $function['name'], HttpTransport::object($decoded));
        }

        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $content = $message['content'] ?? '';

        return new ChatResult(
            text: is_string($content) ? $content : '',
            toolCalls: $calls,
            finishReason: match ($choice['finish_reason'] ?? null) {
                'stop' => FinishReason::Stop,
                'length' => FinishReason::Length,
                'tool_calls', 'function_call' => FinishReason::ToolCalls,
                'content_filter' => FinishReason::ContentFilter,
                default => $calls === [] ? FinishReason::Other : FinishReason::ToolCalls,
            },
            usage: new Usage(
                is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : null,
                is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : null,
            ),
        );
    }
}
