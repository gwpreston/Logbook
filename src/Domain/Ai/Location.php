<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * Where a connection's model runs, from what its host resolves to
 * (spec.md §7.25 *Where it runs*). Shown as a badge with an icon and the
 * words, never colour alone.
 */
enum Location: string
{
    case Server = 'server';
    case Network = 'network';
    case Internet = 'internet';

    public function labelKey(): string
    {
        return 'ai.location.' . $this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Server => 'dns',
            self::Network => 'lan',
            self::Internet => 'public',
        };
    }

    /**
     * The default timeout: local models are slow to start, cloud ones not.
     */
    public function defaultTimeout(): int
    {
        return $this === self::Internet ? 60 : 120;
    }

    /**
     * The wider of two classes: a name with one public address is *Internet*.
     */
    public function widest(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    private function rank(): int
    {
        return match ($this) {
            self::Server => 0,
            self::Network => 1,
            self::Internet => 2,
        };
    }
}
