<?php

declare(strict_types=1);

namespace Logbook\Domain\Odometer;

use DateTimeImmutable;

/**
 * The editable part of a manual reading, validated and in storage units:
 * kilometres as a canonical decimal, the instant in UTC.
 */
final readonly class OdometerReadingData
{
    public function __construct(
        public string $readingKm,
        public DateTimeImmutable $recordedAt,
        public ?string $note = null,
    ) {
    }
}
