<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

use DateTimeImmutable;

/**
 * A model on a connection (spec.md §6 AiModel): listed by the provider or
 * typed by name; only added ones are offered to tasks.
 */
final readonly class AiModel
{
    public function __construct(
        public int $id,
        public int $connectionId,
        public string $name,
        public ?string $label,
        public bool $listed,
        public bool $added,
        public bool $tools,
        public bool $images,
        public bool $json,
        public ?JsonMode $jsonMode,
        public ?DateTimeImmutable $testedAt,
        /** @var list<array{step: string, ok: bool, ms: int, error: ?string}> */
        public array $testResults,
    ) {
    }

    public function has(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Tools => $this->tools,
            Capability::Images => $this->images,
            Capability::Json => $this->json,
        };
    }

    public function displayName(): string
    {
        return $this->label ?? $this->name;
    }
}
