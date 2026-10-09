<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

use DateTimeImmutable;
use Logbook\Support\Units\DistanceUnit;

/**
 * A stored MOT test (spec.md §6 MotTest), with its defects in DVSA's order.
 */
final readonly class MotTest
{
    /**
     * @param list<MotDefect> $defects
     */
    public function __construct(
        public int $id,
        public int $vehicleId,
        public string $number,
        public DateTimeImmutable $completedAt,
        public MotTestResult $result,
        public ?DateTimeImmutable $expiryOn,
        public ?string $odometerKm,
        public ?DistanceUnit $odometerUnit,
        public OdometerState $odometerState,
        public ?string $registrationAtTest,
        public MotDataSource $source,
        public ?DateTimeImmutable $reviewedAt,
        public DateTimeImmutable $fetchedAt,
        public array $defects = [],
        private ?int $counted = null,
    ) {
    }

    /**
     * How many defects the test has: counted by the database when the test
     * was read without them (a list that only shows the number).
     */
    public function defectCount(): int
    {
        return $this->counted ?? count($this->defects);
    }

    public function passed(): bool
    {
        return $this->result === MotTestResult::Passed;
    }

    /**
     * DVSA's own test number, as an `inspection` document's reference; null
     * for a test keyed by its source and time (#334).
     */
    public function reference(): ?string
    {
        return str_contains($this->number, ':') ? null : $this->number;
    }
}
