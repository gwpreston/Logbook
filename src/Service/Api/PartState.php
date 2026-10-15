<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartFitting;

/**
 * A part with its fittings, as the API reads it: what its `ETag` is taken of,
 * so a fitting or removal changes the tag (spec.md §7.20 *Parts and
 * modifications*). Distance and warranty are derived on read and are not in
 * it, so the odometer moving never changes the tag.
 */
final readonly class PartState
{
    /** Who added it, for EntryAccess (ApiEditor reads it). */
    public ?int $createdBy;

    /**
     * @param list<PartFitting> $fittings oldest first
     */
    public function __construct(public Part $part, public array $fittings)
    {
        $this->createdBy = $part->createdBy;
    }
}
