<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

use DateTimeImmutable;
use Logbook\Support\Units\DistanceUnit;

/**
 * One test as DVSA answers it, before it is stored (spec.md §6 MotTest).
 */
final readonly class MotTestRecord
{
    /**
     * @param string $number DVSA's test number, or the key made from the
     *   source and completed time when it gives none (#334)
     * @param string|null $odometerKm canonical decimal, converted from the
     *   tested unit; null unless the odometer was read
     * @param list<MotDefectRecord> $defects in DVSA's order
     */
    public function __construct(
        public string $number,
        public DateTimeImmutable $completedAt,
        public MotTestResult $result,
        public ?DateTimeImmutable $expiryOn,
        public ?string $odometerKm,
        public ?DistanceUnit $odometerUnit,
        public OdometerState $odometerState,
        public ?string $registrationAtTest,
        public MotDataSource $source,
        public array $defects,
    ) {
    }
}
