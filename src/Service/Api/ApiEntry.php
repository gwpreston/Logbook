<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

/**
 * One entry as its list returns it, with the `ETag` of the stored entry
 * (spec.md §7.20 *Phase 39*).
 */
final readonly class ApiEntry
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public array $body,
        public string $tag,
    ) {
    }
}
