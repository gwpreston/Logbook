<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use Logbook\Domain\Part\PartData;

/**
 * What a service record's or modification's form says about parts
 * (spec.md §7.39 *Fitting parts from a record*): the whole state of its
 * *Parts fitted* and *Parts removed* lists, so a part left unticked is a
 * fitting or removal taken back.
 */
final readonly class RecordParts
{
    /**
     * @param list<PartData> $fitNew new parts, created and fitted by the record
     * @param list<int> $fit stored parts fitted again, and the parts the record already fitted that stay
     * @param array<int, PartRemoval> $remove by part id: fitted parts it removes, and the ones it already removed that stay
     * @param list<CameOff> $cameOff *Record what came off*
     */
    public function __construct(
        public array $fitNew = [],
        public array $fit = [],
        public array $remove = [],
        public array $cameOff = [],
    ) {
    }
}
