<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Ai\JsonMode;

/**
 * What *Test* found: each step's outcome, time and redacted error, and
 * for JSON output the mode that worked.
 */
final readonly class TestReport
{
    public function __construct(
        /** @var list<array{step: string, ok: bool, ms: int, error: ?string}> */
        public array $results,
        public ?JsonMode $jsonMode = null,
        /** How many models the list returned, when it ran. */
        public ?int $listed = null,
    ) {
    }

    /**
     * @return array{step: string, ok: bool, ms: int, error: ?string}|null
     */
    public function firstFailure(): ?array
    {
        foreach ($this->results as $result) {
            if (!$result['ok']) {
                return $result;
            }
        }

        return null;
    }

    public function passed(): bool
    {
        foreach ($this->results as $result) {
            if (!$result['ok']) {
                return false;
            }
        }

        return $this->results !== [];
    }
}
