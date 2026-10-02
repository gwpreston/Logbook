<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Domain\Station\Station;

/**
 * Two stations that may be one (spec.md §7.33 *Duplicates*), the older first.
 */
final readonly class DuplicatePair
{
    /**
     * @param non-empty-list<DuplicateReason> $reasons
     */
    public function __construct(
        public Station $first,
        public Station $second,
        public array $reasons,
        /** Straight-line distance in km, when both have a position. */
        public ?float $km = null,
    ) {
    }
}
