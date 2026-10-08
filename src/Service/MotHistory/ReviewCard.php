<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use DateTimeImmutable;

/**
 * The review card (spec.md §7.38 *Review card*): unreviewed tests oldest
 * first, and DVSA's first MOT due date when it is offered.
 */
final readonly class ReviewCard
{
    /**
     * @param list<ReviewTest> $tests oldest first
     */
    public function __construct(
        public array $tests,
        public ?DateTimeImmutable $firstDueOffered = null,
    ) {
    }

    public function pending(): bool
    {
        return $this->tests !== [] || $this->firstDueOffered !== null;
    }

    public function documentsOffered(): int
    {
        return count(array_filter($this->tests, static fn (ReviewTest $test): bool => $test->documentOffered));
    }

    public function issuesOffered(): int
    {
        $count = 0;
        foreach ($this->tests as $test) {
            foreach ($test->defects as $defect) {
                $count += $defect->offered() ? 1 : 0;
            }
        }

        return $count;
    }
}
