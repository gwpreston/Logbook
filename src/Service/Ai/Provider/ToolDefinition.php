<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

/**
 * A tool a model may call: its name, what it does, and its arguments as a
 * JSON Schema object.
 */
final readonly class ToolDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        /** @var array<string, mixed> */
        public array $parameters,
    ) {
    }
}
