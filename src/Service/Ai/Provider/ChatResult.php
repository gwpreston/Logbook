<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

/**
 * What a model answered (spec.md §5 *AI adapters*): text, tool calls, the
 * checked object for a response schema, why it stopped, and usage.
 */
final readonly class ChatResult
{
    /**
     * @param list<ToolCall> $toolCalls
     * @param array<string, mixed>|null $object
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public FinishReason $finishReason,
        public Usage $usage,
        public ?array $object = null,
        /** @var array<mixed>|null the provider's own form of the turn, for tool loops */
        public ?array $raw = null,
        /** The usage log row it was logged as (AiGateway), for the scan's counts. */
        public ?int $requestId = null,
    ) {
    }

    /**
     * The answer as the assistant turn of the next request in a tool loop.
     */
    public function toMessage(): ChatMessage
    {
        return ChatMessage::assistant($this->text, $this->toolCalls, $this->raw);
    }

    /**
     * @param array<string, mixed> $object
     */
    public function withObject(array $object): self
    {
        return new self($this->text, $this->toolCalls, $this->finishReason, $this->usage, $object, $this->raw, $this->requestId);
    }

    public function withRequestId(int $id): self
    {
        return new self($this->text, $this->toolCalls, $this->finishReason, $this->usage, $this->object, $this->raw, $id);
    }
}
