<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Ai\AdapterType;

/**
 * The connection presets (spec.md §7.25 *Presets*): an adapter and a base
 * URL, both editable. *Other OpenAI-compatible* leaves the URL to the admin.
 */
enum ConnectionPreset: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case OpenRouter = 'openrouter';
    case Groq = 'groq';
    case Mistral = 'mistral';
    case Together = 'together';
    case DeepSeek = 'deepseek';
    case Ollama = 'ollama';
    case LlamaCpp = 'llamacpp';
    case LmStudio = 'lmstudio';
    case Other = 'other';

    public function adapter(): AdapterType
    {
        return match ($this) {
            self::Anthropic => AdapterType::Anthropic,
            self::Gemini => AdapterType::Gemini,
            self::Ollama => AdapterType::Ollama,
            default => AdapterType::OpenAiCompatible,
        };
    }

    public function url(): string
    {
        return match ($this) {
            self::OpenAi => 'https://api.openai.com/v1',
            self::Anthropic => 'https://api.anthropic.com',
            self::Gemini => 'https://generativelanguage.googleapis.com',
            self::OpenRouter => 'https://openrouter.ai/api/v1',
            self::Groq => 'https://api.groq.com/openai/v1',
            self::Mistral => 'https://api.mistral.ai/v1',
            self::Together => 'https://api.together.xyz/v1',
            self::DeepSeek => 'https://api.deepseek.com/v1',
            self::Ollama => 'http://localhost:11434',
            self::LlamaCpp => 'http://localhost:8080/v1',
            self::LmStudio => 'http://localhost:1234/v1',
            self::Other => '',
        };
    }

    public function labelKey(): string
    {
        return 'ai.preset.' . $this->value;
    }

    /**
     * Hosts of gateways that pass each request on to a provider they
     * choose per model: their acknowledgement names that too.
     */
    public static function isGateway(string $host): bool
    {
        return in_array(strtolower($host), ['openrouter.ai'], true);
    }
}
