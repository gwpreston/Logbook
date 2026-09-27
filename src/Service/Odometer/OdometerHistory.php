<?php

declare(strict_types=1);

namespace Logbook\Service\Odometer;

use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Support\Number\Decimal;

/**
 * A vehicle's whole mileage series (manual, fill-up and maintenance readings
 * together) with plausibility warnings.
 */
final readonly class OdometerHistory
{
    /** Average month length in days (365.2425 / 12). */
    private const float DAYS_PER_MONTH = 30.436875;
    /** Below this span a monthly average would be a wild extrapolation. */
    private const int MIN_DAYS_FOR_AVERAGE = 7;

    /** @var array<int, OdometerWarning> */
    public array $warnings;

    /**
     * @param list<OdometerReading> $readings oldest first
     */
    public function __construct(public array $readings)
    {
        $this->warnings = OdometerPlausibility::check($readings);
    }

    public function isEmpty(): bool
    {
        return $this->readings === [];
    }

    /**
     * @return list<OdometerReading> newest first
     */
    public function newestFirst(): array
    {
        return array_reverse($this->readings);
    }

    /**
     * The most recent reading in time (the current odometer, if nothing is odd).
     */
    public function latest(): ?OdometerReading
    {
        return $this->readings === [] ? null : $this->readings[array_key_last($this->readings)];
    }

    public function first(): ?OdometerReading
    {
        return $this->readings[0] ?? null;
    }

    public function warningFor(int $readingId): ?OdometerWarning
    {
        return $this->warnings[$readingId] ?? null;
    }

    /**
     * Kilometres since the reading before this one, keyed by reading id
     * (absent for the first reading).
     *
     * @return array<int, string>
     */
    public function deltas(): array
    {
        $deltas = [];
        $previous = null;
        foreach ($this->readings as $reading) {
            if ($previous !== null) {
                $deltas[$reading->id] = Decimal::subtract($reading->readingKm, $previous->readingKm);
            }
            $previous = $reading;
        }

        return $deltas;
    }

    /**
     * Kilometres from the first reading to the latest one.
     */
    public function totalDistanceKm(): ?string
    {
        $first = $this->first();
        $latest = $this->latest();
        if ($first === null || $latest === null || $first === $latest) {
            return null;
        }

        return Decimal::subtract($latest->readingKm, $first->readingKm);
    }

    /**
     * Average kilometres per month between the first and latest readings, or
     * null with too little history (under a week, or going backwards overall).
     */
    public function averageKmPerMonth(): ?float
    {
        $first = $this->first();
        $latest = $this->latest();
        $distance = $this->totalDistanceKm();
        if ($first === null || $latest === null || $distance === null || Decimal::compare($distance, '0') < 0) {
            return null;
        }

        $days = ($latest->recordedAt->getTimestamp() - $first->recordedAt->getTimestamp()) / 86400;
        if ($days < self::MIN_DAYS_FOR_AVERAGE) {
            return null;
        }

        return (float) $distance / $days * self::DAYS_PER_MONTH;
    }
}
