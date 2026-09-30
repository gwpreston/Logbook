<?php

declare(strict_types=1);

namespace Logbook\Domain\Trip;

/**
 * How a journey reads everywhere it is shown (spec.md §7.22).
 */
final class Journey
{
    public static function label(string $from, string $to, bool $isReturn): string
    {
        return $isReturn ? sprintf('%s → %s → %s', $from, $to, $from) : sprintf('%s → %s', $from, $to);
    }
}
