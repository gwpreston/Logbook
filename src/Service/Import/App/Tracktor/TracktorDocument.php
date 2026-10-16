<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

/**
 * One of Tracktor's `complianceDocuments`, or (Tracktor 1.x) one of its
 * `insurances` (type `insurance`) or `puccs` (type `emissions`), read into
 * the same shape.
 */
final readonly class TracktorDocument
{
    public const array TYPES = ['insurance', 'emissions', 'roadworthiness', 'registration', 'other'];

    public function __construct(
        public int $line,
        public string $id,
        /** One of TYPES, as written (an unknown one is kept for the preview). */
        public string $type,
        /** The name of an `other` document. */
        public string $otherLabel,
        public string $number,
        public string $issuer,
        public ?string $startDate,
        public ?string $endDate,
        /** `none`, `yearly`, `monthly` or `no_end`. */
        public string $recurrence,
        public int $interval,
        public ?string $cost,
        public string $notes,
        public ?string $attachment,
        /** The list it came from: `complianceDocuments`, `insurances` or `puccs`. */
        public string $list = 'complianceDocuments',
    ) {
    }

    public function recurs(): bool
    {
        return $this->recurrence === 'yearly' || $this->recurrence === 'monthly';
    }
}
