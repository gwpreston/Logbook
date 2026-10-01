<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * How a model is made to answer with a JSON object (spec.md §7.25
 * *Structured output*), best first. Each result passes the same schema check.
 */
enum JsonMode: string
{
    /** `response_format: json_schema`, Gemini `responseJsonSchema`. */
    case JsonSchema = 'json_schema';
    /** `response_format: json_object`, the schema in the instructions. */
    case JsonObject = 'json_object';
    /** One forced tool call whose arguments are the object. */
    case Tool = 'tool';

    /**
     * The modes *Test* tries for an adapter, best first.
     *
     * @return list<self>
     */
    public static function candidates(AdapterType $adapter): array
    {
        return match ($adapter) {
            // Anthropic's `output_config.format`; the newest models refuse a forced tool.
            AdapterType::Anthropic, AdapterType::Gemini => [self::JsonSchema, self::Tool],
            // Ollama's /v1 cannot force a tool.
            AdapterType::Ollama => [self::JsonSchema, self::JsonObject],
            default => [self::JsonSchema, self::JsonObject, self::Tool],
        };
    }
}
