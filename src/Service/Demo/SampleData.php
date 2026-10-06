<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use DateTimeImmutable;

/**
 * What the demo is made of (spec.md §7.36): the sample garage, seeded into
 * empty tables on the caller's connection and transaction.
 */
interface SampleData
{
    /**
     * Seeds the empty tables: the one account `demo`, the sample garage,
     * every date placed relative to $today.
     */
    public function seed(string $password, DateTimeImmutable $today): void;
}
