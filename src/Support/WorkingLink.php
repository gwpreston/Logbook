<?php

declare(strict_types=1);

namespace Logbook\Support;

/**
 * A route to the row a working's input came from; the page resolves it with
 * url_for(), so it follows the base path (spec.md §8 *Show the working*).
 */
final readonly class WorkingLink
{
    /**
     * @param array<string, int|string> $params
     * @param array<string, int|string> $query
     */
    public function __construct(
        public string $route,
        public array $params = [],
        public array $query = [],
    ) {
    }
}
