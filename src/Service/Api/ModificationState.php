<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartFitting;

/**
 * A modification with the fittings it made and ended, as the API reads it:
 * what its `ETag` is taken of (spec.md §7.20 *Parts and modifications*).
 */
final readonly class ModificationState
{
    /** Who logged it, for EntryAccess (ApiEditor reads it). */
    public ?int $createdBy;

    /**
     * @param list<array{part: Part, fitting: PartFitting}> $made
     */
    public function __construct(public Modification $modification, public array $made)
    {
        $this->createdBy = $modification->createdBy;
    }
}
