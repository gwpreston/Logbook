<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use DateTimeImmutable;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelGrade;

/**
 * A sustained economy change on one series (EconomyDrift), with the facts
 * behind its likely causes. Quantities are canonical decimals (km, litres
 * or kWh); a figure is volume × 100 ÷ distance, worded in the viewer's unit.
 */
final readonly class DriftFinding
{
    /**
     * @param list<int> $closingIds the recent segments' closing fill-ups (the fingerprint)
     */
    public function __construct(
        public EnergyKind $kind,
        /** How many recent segments (3–5). */
        public int $tanks,
        public string $recentDistanceKm,
        public string $recentVolume,
        public string $baselineDistanceKm,
        public string $baselineVolume,
        public array $closingIds,
        /** Whether the same months a year earlier held enough segments to compare (else it may be the season). */
        public bool $seasonChecked,
        /** The baseline's most common grade, when the recent segments' differs. */
        public ?FuelGrade $gradeFrom = null,
        /** The recent segments' most common grade, when it differs from the baseline's. */
        public ?FuelGrade $gradeTo = null,
        /** The latest tyre fitting within the recent window (local date). */
        public ?DateTimeImmutable $tyresFittedOn = null,
        /** Every recent segment ended in November–February and the baseline's did not all. */
        public bool $winter = false,
        /** A service schedule is overdue. */
        public bool $serviceOverdue = false,
        /** The recent segments' mean distance is under half the baseline median. */
        public bool $shortTanks = false,
    ) {
    }

    public function isElectric(): bool
    {
        return $this->kind === EnergyKind::Electric;
    }
}
