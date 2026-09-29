<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\TyrePosition;

/**
 * The first point where a replay fails: which change, which tyre and why.
 * The service turns it into a message naming them.
 */
final readonly class TyreSequenceError
{
    public function __construct(
        public TyreSequenceProblem $problem,
        public int $changeId,
        public int $tyreId,
        public ?TyrePosition $position = null,
    ) {
    }
}
