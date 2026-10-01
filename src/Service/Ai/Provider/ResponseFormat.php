<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

use Logbook\Domain\Ai\JsonMode;

/**
 * Ask for a JSON object matching a schema, by one of the three modes
 * (spec.md §7.25 *Structured output*).
 */
final readonly class ResponseFormat
{
    public function __construct(
        /** A name for the object (letters, digits, `_`), e.g. `receipt`. */
        public string $name,
        /** @var array<string, mixed> a JSON Schema object */
        public array $schema,
        public JsonMode $mode = JsonMode::JsonSchema,
    ) {
    }

    public function withMode(JsonMode $mode): self
    {
        return new self($this->name, $this->schema, $mode);
    }

    /**
     * The instruction added for `json_object`, which cannot carry a schema.
     */
    public function instruction(): string
    {
        return "Answer with one JSON object only, no other text, matching this JSON Schema:\n"
            . json_encode($this->schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
