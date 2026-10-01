<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

/**
 * A provider-neutral request (spec.md §5 *AI adapters*). The model is
 * filled in by AiGateway from the task's assignment.
 */
final readonly class ChatRequest
{
    /**
     * @param list<ChatMessage> $messages
     * @param list<ToolDefinition> $tools
     */
    public function __construct(
        public array $messages,
        public string $system = '',
        public array $tools = [],
        public ?ResponseFormat $response = null,
        public string $model = '',
        public ?float $temperature = null,
        public ?int $maxOutputTokens = null,
    ) {
    }

    public function withModel(string $model, ?float $temperature, ?int $maxOutputTokens): self
    {
        return new self(
            $this->messages,
            $this->system,
            $this->tools,
            $this->response,
            $model,
            $this->temperature ?? $temperature,
            $this->maxOutputTokens ?? $maxOutputTokens,
        );
    }

    public function withResponse(?ResponseFormat $response): self
    {
        return new self(
            $this->messages,
            $this->system,
            $this->tools,
            $response,
            $this->model,
            $this->temperature,
            $this->maxOutputTokens,
        );
    }

    /**
     * Every message's text, for the usage log with `AI_LOG_CONTENT=true`.
     */
    public function transcript(): string
    {
        $lines = $this->system === '' ? [] : ['system: ' . $this->system];
        foreach ($this->messages as $message) {
            $images = $message->images === [] ? '' : sprintf(' [%d image(s)]', count($message->images));
            $lines[] = $message->role->value . ': ' . $message->text . $images;
        }

        return implode("\n", $lines);
    }
}
