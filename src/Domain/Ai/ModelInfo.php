<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * A model as a provider lists it (spec.md §7.25 *Models*). Capabilities
 * are what the provider reports, or none.
 */
final readonly class ModelInfo
{
    public function __construct(
        public string $name,
        public ?string $label = null,
        /** @var list<Capability> */
        public array $capabilities = [],
    ) {
    }
}
