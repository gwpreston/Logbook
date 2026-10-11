<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Golden;

/**
 * A small seeded generator (xorshift64*), so the same seed gives the same
 * numbers on every PHP version and platform. Integers only: no floats reach
 * an amount.
 */
final class Rng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed === 0 ? 0x2545F491 : $seed;
    }

    /** A whole number from $min to $max, both inclusive. */
    public function between(int $min, int $max): int
    {
        return $min + $this->next() % ($max - $min + 1);
    }

    /** True about once in $n draws. */
    public function oneIn(int $n): bool
    {
        return $this->between(1, $n) === 1;
    }

    private function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & PHP_INT_MAX;
        $x ^= $x >> 7;
        $x ^= ($x << 17) & PHP_INT_MAX;
        $this->state = $x & PHP_INT_MAX;

        return $this->state === 0 ? 1 : $this->state;
    }
}
