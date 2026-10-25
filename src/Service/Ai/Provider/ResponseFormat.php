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
        /**
         * @var array<string, mixed>|null what the answer is checked against, when looser than what the
         *      model is held to (Phase 53.8, #534): a provider that doesn't enforce the schema may leave
         *      a key out without the answer being refused
         */
        public ?array $check = null,
        /**
         * @var array<string, mixed>|null the object with no unions, optional keys or length limits, `""` for
         *      null and `[]` for no list, for a provider whose `json_schema` refuses them (Anthropic, #553)
         */
        public ?array $flat = null,
    ) {
    }

    public function withMode(JsonMode $mode): self
    {
        return new self($this->name, $this->schema, $mode, $this->check, $this->flat);
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
