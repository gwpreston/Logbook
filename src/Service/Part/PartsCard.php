<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use Logbook\Domain\Part\Modification;
use Logbook\Support\Money\Money;

/**
 * The overview's *Modifications* card (spec.md §7.39 *Pages*): current
 * modifications, newest first (the card shows five), what the build has
 * cost, and how many parts are in warranty.
 */
final readonly class PartsCard
{
    /**
     * @param list<Modification> $current
     */
    public function __construct(
        public array $current,
        public int $currentCount,
        /** Every modification's cost, all time (build spend); in the vehicle's currency. */
        public Money $buildSpend,
        public int $inWarranty,
    ) {
    }
}
