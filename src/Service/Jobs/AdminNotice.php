<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

/**
 * One notice in the dashboard's admin notice area (spec.md §7.30): its
 * key (what *Dismiss* records), its words and the page that fixes it.
 */
final readonly class AdminNotice
{
    /**
     * @param array<string, mixed> $params for the message (dates stay
     *        DateTimeImmutable, for the template to format)
     * @param array<string, string|int> $linkData the route's arguments
     */
    public function __construct(
        public string $key,
        public string $messageKey,
        public array $params,
        public string $linkRoute,
        public array $linkData,
        public string $linkLabelKey,
        public string $level = 'warning',
    ) {
    }
}
