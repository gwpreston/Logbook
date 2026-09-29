<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Logbook\Service\Attachment\PendingUploads;

/**
 * The purchase and sale paperwork chosen in one vehicle save (spec.md
 * §7.12), each already checked. Stored all or nothing, together with the
 * vehicle.
 */
final readonly class OwnershipFiles
{
    public function __construct(
        public PendingUploads $purchase = new PendingUploads(),
        public PendingUploads $sale = new PendingUploads(),
    ) {
    }

    /**
     * Both inputs' files in one batch, purchase first (the order they are
     * written in).
     */
    public function all(): PendingUploads
    {
        return new PendingUploads([...$this->purchase->files, ...$this->sale->files]);
    }
}
