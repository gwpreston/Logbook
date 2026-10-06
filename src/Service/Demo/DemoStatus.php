<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

/**
 * The guard's answer for this database and this environment (spec.md §7.36).
 */
final readonly class DemoStatus
{
    public function __construct(
        public DemoState $state,
        public ?DemoRefusal $refusal = null,
        public ?DemoMarker $marker = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->state === DemoState::Active;
    }
}
