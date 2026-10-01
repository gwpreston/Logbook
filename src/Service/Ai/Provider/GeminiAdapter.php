<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Domain\Ai\ModelInfo;

/**
 * Google's Gemini API (spec.md §5 *AI adapters*): `generateContent` with
 * function declarations and `responseJsonSchema`. The key travels in the
 * `x-goog-api-key` header, never the URL. The base URL is
 * `https://generativelanguage.googleapis.com`.
 */
final readonly class GeminiAdapter implements ProviderAdapter
{
    private const string API = 'v1beta';

    /** Finish reasons that mean the answer was blocked. */
    private const array FILTERED = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'];

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

        $body = ['contents' => $this->contents($request)];
        if ($request->system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $request->system]]];
        }
        $tools = $request->tools;
        if ($format !== null && $format->mode === JsonMode::Tool) {
            $tools[] = new ToolDefinition($format->name, 'Return the answer as this object.', $format->schema);
            $body['toolConfig'] = ['functionCallingConfig' => ['mode' => 'ANY', 'allowedFunctionNames' => [$format->name]]];
        }
        if ($tools !== []) {
            $body['tools'] = [['functionDeclarations' => array_map(static fn (ToolDefinition $tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
            ] + ($tool->parameters === [] ? [] : ['parametersJsonSchema' => $tool->parameters]), $tools)]];
        }
        $config = [];
        if ($request->temperature !== null) {
            $config['temperature'] = $request->temperature;
        }
        if ($request->maxOutputTokens !== null) {
            $config['maxOutputTokens'] = $request->maxOutputTokens;
        }
        if ($format !== null && $format->mode === JsonMode::JsonSchema) {
            $config['responseMimeType'] = 'application/json';
            $config['responseJsonSchema'] = $format->schema;
        }
        if ($config !== []) {
            $body['generationConfig'] = $config;
        }

        $path = sprintf('%s/models/%s:generateContent', self::API, rawurlencode(self::bare($request->model)));
        $result = $this->parse($this->transport->post($this->target, $path, $body));

        return $format === null ? $result : StructuredOutput::extract($result, $format);
    }

    public function listModels(): array
    {
        $models = [];
        $token = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['pageSize' => '1000'] + ($token === null ? [] : ['pageToken' => $token]);
            $response = $this->transport->get($this->target, self::API . '/models', $query);
            $list = $response['models'] ?? null;
            if (!is_array($list)) {
                throw new ProviderError(ErrorCode::BadResponse, 'The model list has no "models".');
            }
            foreach ($list as $item) {
                if (!is_array($item) || !is_string($item['name'] ?? null)) {
                    continue;
                }
                $methods = is_array($item['supportedGenerationMethods'] ?? null) ? $item['supportedGenerationMethods'] : [];
                if (!in_array('generateContent', $methods, true)) {
                    continue;
                }
                $label = $item['displayName'] ?? null;
                $models[] = new ModelInfo(self::bare($item['name']), is_string($label) ? $label : null);
            }
            $next = $response['nextPageToken'] ?? null;
            if (!is_string($next) || $next === '') {
                break;
            }
            $token = $next;
        }

        return $models;
    }

    /**
     * "models/gemini-2.5-flash" → "gemini-2.5-flash".
     */
    private static function bare(string $name): string
    {
        return str_starts_with($name, 'models/') ? substr($name, 7) : $name;
    }

    /**
     * Gemini's roles are `user` and `model`; a tool's result is a user
     * turn with a `functionResponse` part (echoing the call's id), and
     * consecutive turns of one role are merged. A model turn from this API
     * goes back exactly as it came, with its thought signatures.
     *
     * @return list<array{role: string, parts: array<mixed>}> raw turns are opaque
     */
    private function contents(ChatRequest $request): array
    {
        $contents = [];
        foreach ($request->messages as $message) {
            [$role, $parts] = match ($message->role) {
                Role::User => ['user', [
                    ['text' => $message->text],
                    ...array_map(static fn (ImageInput $image): array => [
                        'inlineData' => ['mimeType' => $image->mediaType, 'data' => $image->base64()],
                    ], $message->images),
                ]],
                Role::Assistant => ['model', $message->raw ?? [
                    ...($message->text === '' ? [] : [['text' => $message->text]]),
                    ...array_map(static fn (ToolCall $call): array => [
                        'functionCall' => ['name' => $call->name, 'args' => (object) $call->arguments],
                    ], $message->toolCalls),
                ]],
                Role::Tool => ['user', [[
                    'functionResponse' => [
                        'id' => (string) $message->toolCallId,
                        'name' => (string) $message->toolName,
                        'response' => (object) (StructuredOutput::decode($message->text) ?? ['content' => $message->text]),
                    ],
                ]]],
            };
            $last = array_key_last($contents);
            if ($last !== null && $contents[$last]['role'] === $role) {
                $contents[$last]['parts'] = [...$contents[$last]['parts'], ...$parts];
                continue;
            }
            $contents[] = ['role' => $role, 'parts' => $parts];
        }

        return $contents;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function parse(array $response): ChatResult
    {
        $metadata = is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [];
        $count = static fn (string $key): ?int => is_int($metadata[$key] ?? null) ? $metadata[$key] : null;
        // Zero counts may be left out; thinking is output too.
        $usage = $metadata === [] ? new Usage() : new Usage(
            $count('promptTokenCount') ?? 0,
            ($count('candidatesTokenCount') ?? 0) + ($count('thoughtsTokenCount') ?? 0),
        );
        $candidates = $response['candidates'] ?? null;
        $candidate = is_array($candidates) && is_array($candidates[0] ?? null) ? $candidates[0] : null;
        if ($candidate === null) {
            $feedback = is_array($response['promptFeedback'] ?? null) ? $response['promptFeedback'] : [];
            if (isset($feedback['blockReason'])) {
                return new ChatResult('', [], FinishReason::ContentFilter, $usage);
            }
            throw new ProviderError(ErrorCode::BadResponse, 'The answer has no "candidates".');
        }

        $text = '';
        $calls = [];
        $content = is_array($candidate['content'] ?? null) ? $candidate['content'] : [];
        $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (is_string($part['text'] ?? null) && ($part['thought'] ?? false) !== true) {
                $text .= $part['text'];
            }
            $call = $part['functionCall'] ?? null;
            if (is_array($call) && is_string($call['name'] ?? null)) {
                $args = is_array($call['args'] ?? null) ? $call['args'] : [];
                $id = is_string($call['id'] ?? null) ? $call['id'] : 'call_' . count($calls);
                $calls[] = new ToolCall($id, $call['name'], HttpTransport::object($args));
            }
        }

        return new ChatResult(
            text: $text,
            toolCalls: $calls,
            finishReason: match (true) {
                $calls !== [] => FinishReason::ToolCalls,
                ($candidate['finishReason'] ?? null) === 'STOP' => FinishReason::Stop,
                ($candidate['finishReason'] ?? null) === 'MAX_TOKENS' => FinishReason::Length,
                in_array($candidate['finishReason'] ?? null, self::FILTERED, true)
                    => FinishReason::ContentFilter,
                default => FinishReason::Other,
            },
            usage: $usage,
            raw: $parts,
        );
    }
}
