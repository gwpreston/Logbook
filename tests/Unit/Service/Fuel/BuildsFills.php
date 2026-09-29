<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Fuel;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Support\Number\Decimal;

/**
 * Fill-ups for the fuel insight tests: a UTC instant, odometer, volume and
 * price; the total is price × volume.
 */
trait BuildsFills
{
    private int $nextFillId = 1;

    private function fillAt(
        string $at,
        string $km,
        string $volume,
        string $price,
        ?FuelGrade $grade = null,
        ?Fuel $fuel = null,
        bool $partial = false,
        ?string $total = null,
        bool $missedPrevious = false,
    ): FuelEntry {
        $when = new DateTimeImmutable($at, new DateTimeZone('UTC'));

        return new FuelEntry(
            $this->nextFillId++,
            1,
            new FuelEntryData(
                filledAt: $when,
                odometerKm: $km,
                fuel: $fuel ?? $grade?->family() ?? Fuel::Petrol,
                volume: $volume,
                pricePerUnit: $price,
                totalCost: $total ?? Decimal::multiply($price, $volume, 2),
                isPartial: $partial,
                isMissedPrevious: $missedPrevious,
                grade: $grade,
            ),
            $when,
            $when,
        );
    }
}
