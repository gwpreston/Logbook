<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * How Logbook talks to a model provider (spec.md §7.25). Four cover every
 * runtime and service: most speak OpenAI's Chat Completions.
 */
enum AdapterType: string
{
    case OpenAiCompatible = 'openai_compatible';
    case Ollama = 'ollama';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';

    public function labelKey(): string
    {
        return 'ai.adapter.' . $this->value;
    }

    /**
     * Whether the adapter can constrain output to a JSON Schema natively.
     * Anthropic uses a forced tool call instead.
     */
    public function hasJsonSchema(): bool
    {
        return $this !== self::Anthropic;
    }
}
