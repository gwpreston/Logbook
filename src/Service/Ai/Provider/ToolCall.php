<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

/**
 * A model's call to a tool, with its decoded arguments.
 */
final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        /** @var array<string, mixed> */
        public array $arguments,
    ) {
    }
}
