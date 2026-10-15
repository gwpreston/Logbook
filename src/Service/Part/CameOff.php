<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use Logbook\Domain\Part\PartData;

/**
 * A *Record what came off* row (spec.md §7.39): a part that was on the car
 * before it was logged, made `original` and removed by the record.
 */
final readonly class CameOff
{
    public function __construct(
        public PartData $part,
        public PartRemoval $removal = new PartRemoval(),
    ) {
    }
}
