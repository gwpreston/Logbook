<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

use DateTimeImmutable;

/**
 * DVSA's answer for one vehicle (spec.md §4, §7.38): what it knows of the
 * vehicle, its recall state, and its tests newest first. A new vehicle has
 * no tests and may carry its first MOT due date.
 */
final readonly class MotVehicleRecord
{
    /**
     * @param list<MotTestRecord> $tests newest first
     * @param int $undated tests skipped for having no completed date (#334)
     */
    public function __construct(
        public ?string $registration,
        public ?string $make,
        public ?string $model,
        public ?string $fuelType,
        public ?string $colour,
        public ?DateTimeImmutable $firstUsedOn,
        public ?DateTimeImmutable $registeredOn,
        public ?DateTimeImmutable $firstDueOn,
        public RecallState $recall,
        public array $tests,
        public int $undated = 0,
    ) {
    }
}
