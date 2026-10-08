<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Logbook\Domain\MotHistory\MotTest;

/**
 * One unreviewed test on the review card (spec.md §7.38 *Review card*):
 * whether it is offered as an `inspection` document, its defects, and the
 * issues from the test before that were not advised this time.
 */
final readonly class ReviewTest
{
    /**
     * @param list<ReviewDefect> $defects
     * @param list<int> $notSeenAgain issue ids from the previous test's defects not on this one
     */
    public function __construct(
        public MotTest $test,
        public bool $documentOffered,
        public array $defects,
        public array $notSeenAgain = [],
    ) {
    }

    public function anythingOffered(): bool
    {
        if ($this->documentOffered) {
            return true;
        }
        foreach ($this->defects as $defect) {
            if ($defect->offered()) {
                return true;
            }
        }

        return false;
    }
}
