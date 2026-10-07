<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

/**
 * What a service said when its token was checked on saving (spec.md
 * §7.11, #261): it works (with a name to show), it was rejected (not
 * saved), or it couldn't be asked (saved, with a notice).
 */
final readonly class Verification
{
    private function __construct(
        public string $outcome,
        /** The bot's or workspace's name, for the saved message only. */
        public ?string $name = null,
        /** ReplyWords for a rejection. */
        public ?string $words = null,
    ) {
    }

    public static function works(?string $name = null): self
    {
        return new self('works', $name);
    }

    public static function rejected(string $words): self
    {
        return new self('rejected', words: $words);
    }

    public static function unreachable(): self
    {
        return new self('unreachable');
    }

    public function isRejected(): bool
    {
        return $this->outcome === 'rejected';
    }

    public function isUnreachable(): bool
    {
        return $this->outcome === 'unreachable';
    }
}
