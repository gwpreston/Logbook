<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

/**
 * Telegram *Find my chat*'s answer: the private chats found (id and first
 * name, newest first), or why none could be listed (ReplyWords or a connection error).
 */
final readonly class FoundChats
{
    /**
     * @param list<array{id: string, name: string}> $chats
     */
    private function __construct(public array $chats, public ?string $error)
    {
    }

    /**
     * @param list<array{id: string, name: string}> $chats
     */
    public static function found(array $chats): self
    {
        return new self($chats, null);
    }

    public static function failed(string $error): self
    {
        return new self([], $error);
    }
}
