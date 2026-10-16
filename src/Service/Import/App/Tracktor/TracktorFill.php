<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

/**
 * One of Tracktor's `fuelLogs`. Numbers are decimal text, as the JSON had
 * them; null when the field is empty.
 */
final readonly class TracktorFill
{
    public function __construct(
        /** Its position in `fuelLogs`, for messages. */
        public int $line,
        public string $id,
        /** Calendar date, `Y-m-d`; null when unreadable. */
        public ?string $date,
        public ?string $odometer,
        public ?string $amount,
        public ?string $rate,
        public ?string $cost,
        public bool $full,
        public bool $missedLast,
        public string $notes,
        public ?string $attachment,
    ) {
    }
}
