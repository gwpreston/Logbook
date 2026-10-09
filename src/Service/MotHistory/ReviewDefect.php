<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Logbook\Domain\MotHistory\MotDefect;

/**
 * One defect on the review card (spec.md §7.38 *Defects → issues*).
 */
final readonly class ReviewDefect
{
    /**
     * @param bool $repeat advised again: its issue got an update instead of an offer
     */
    public function __construct(
        public MotDefect $defect,
        public bool $repeat = false,
    ) {
    }

    public function offered(): bool
    {
        return !$this->defect->settled();
    }
}
