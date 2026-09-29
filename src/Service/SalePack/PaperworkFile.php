<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use DateTimeImmutable;
use Logbook\Domain\Attachment\Attachment;

/**
 * One file the sale pack's ZIP can hold (spec.md §7.19): the attachment,
 * what it belongs to and the name it has in the ZIP.
 */
final readonly class PaperworkFile
{
    public function __construct(
        public Attachment $attachment,
        public PaperworkKind $kind,
        /** The entry's calendar date (midnight UTC). */
        public DateTimeImmutable $date,
        /** The entry's odometer (km), when it has one. */
        public ?string $odometerKm,
        /** What the entry was, as the owner wrote it ("Annual service, Kwik Fit"). */
        public string $entry,
        /** Its name in the ZIP: "2024-03-12 Service - Kwik Fit.pdf", unique in the ZIP. */
        public string $name,
        /** Whether it goes in the ZIP (false once the seller unticks it). */
        public bool $included = true,
    ) {
    }

    public function id(): int
    {
        return $this->attachment->id;
    }
}
