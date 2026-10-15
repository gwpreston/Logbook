<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use Logbook\Domain\Part\RemovalOutcome;

/**
 * What happened to a part as it came off: the outcome, and a sale price
 * (only when sold) or the part that replaced it (only when returned).
 */
final readonly class PartRemoval
{
    public function __construct(
        public RemovalOutcome $outcome = RemovalOutcome::Kept,
        /** Canonical decimal; 0 is allowed. */
        public ?string $soldPrice = null,
        public ?int $replacedBy = null,
    ) {
    }
}
