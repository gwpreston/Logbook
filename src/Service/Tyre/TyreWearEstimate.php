<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Support\Number\Decimal;

/**
 * A tyre's tread as last measured and, when there is enough to go on, as
 * estimated today (spec.md §7.17). Derived on every read; never stored.
 * Depths are millimetres, distances kilometres (canonical decimals).
 */
final readonly class TyreWearEstimate
{
    public function __construct(
        /** The latest measurement, if any. */
        public ?TyreMeasurement $latest = null,
        /** The depth it is judged against (the owner's replace-at for this tyre). */
        public ?string $replaceAtMm = null,
        /** Wear, mm per 1,000 km (positive), when known. */
        public ?string $ratePer1000Km = null,
        /** The latest measurement moved on by the wear since it; null when not known. */
        public ?string $depthNowMm = null,
        /** Distance until replace-at (0 once there); null when not known. */
        public ?string $kmLeft = null,
        /** The odometer reading it reaches replace-at at. */
        public ?string $wearOutKm = null,
        /** When, from the average daily distance; null without a week of history. */
        public ?DateTimeImmutable $wearOutOn = null,
        /** At or under replace-at, measured or estimated now. */
        public bool $worn = false,
        public ?TyreLegalFlag $legal = null,
        /** The first measurement (Phase 33.3: the tread bar's full scale). */
        public ?TyreMeasurement $first = null,
        /** How many times it has been measured. */
        public int $count = 0,
        /** The owner's legal minimum for this tyre (the tread bar's empty end). */
        public ?string $legalMm = null,
    ) {
    }

    /**
     * The tread bar's fill (spec.md §7.17, Phase 33.3, #184), 0–100: the
     * latest depth between the first measured depth (full) and the legal
     * minimum (empty), clamped. Null until the tyre has two measurements,
     * so no new depth is ever assumed.
     */
    public function barPercent(): ?int
    {
        if ($this->count < 2 || $this->latest === null || $this->first === null || $this->legalMm === null) {
            return null;
        }
        $range = Decimal::subtract($this->first->treadMm, $this->legalMm);
        if (Decimal::compare($range, '0') <= 0) {
            return 0;
        }
        $ratio = Decimal::divide(Decimal::subtract($this->latest->treadMm, $this->legalMm), $range, 4);
        $percent = (int) round((float) $ratio * 100);

        return max(0, min(100, $percent));
    }

    /**
     * Whether depth now and distance left are known (a fitted road tyre with
     * enough measurements).
     */
    public function isKnown(): bool
    {
        return $this->depthNowMm !== null;
    }

    public function isMeasured(): bool
    {
        return $this->latest !== null;
    }
}
