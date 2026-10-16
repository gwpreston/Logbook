<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

/**
 * One of Tracktor's `maintenanceLogs`: no title and no category.
 */
final readonly class TracktorServiceLog
{
    public function __construct(
        public int $line,
        public string $id,
        public ?string $date,
        public ?string $odometer,
        public string $serviceCenter,
        public ?string $cost,
        public string $notes,
        public ?string $attachment,
    ) {
    }
}
