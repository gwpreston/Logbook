<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

/**
 * One turn of a conversation, provider-neutral (spec.md §5 *AI adapters*).
 */
final readonly class ChatMessage
{
    /**
     * @param list<ImageInput> $images
     * @param list<ToolCall> $toolCalls
     */
    private function __construct(
        public Role $role,
        public string $text,
        public array $images = [],
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        /**
         * The provider's own form of an assistant turn, sent back unchanged
         * to the same provider (Anthropic's thinking blocks, Gemini's
         * thought signatures); null otherwise.
         *
         * @var array<mixed>|null
         */
        public ?array $raw = null,
    ) {
    }

    /**
     * @param list<ImageInput> $images
     */
    public static function user(string $text, array $images = []): self
    {
        return new self(Role::User, $text, $images);
    }

    /**
     * @param list<ToolCall> $toolCalls
     * @param array<mixed>|null $raw the provider's form of the turn (ChatResult::toMessage())
     */
    public static function assistant(string $text, array $toolCalls = [], ?array $raw = null): self
    {
        return new self(Role::Assistant, $text, [], $toolCalls, null, null, $raw);
    }

    /**
     * A tool's result; $content is usually JSON.
     */
    public static function toolResult(ToolCall $call, string $content): self
    {
        return new self(Role::Tool, $content, [], [], $call->id, $call->name);
    }
}
