<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Domain\Tyre\TyreSeason;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DepthUnit;

/**
 * An owner's tyre thresholds (spec.md §7.17, Settings → Tyres): when to
 * replace, the legal minimum and the age limit. Depths are millimetres
 * (canonical, 3 places). Stored as the `tyres.thresholds` user setting.
 */
final readonly class TyreThresholds
{
    public const string DEFAULT_CAR_REPLACE_MM = '3.000';
    public const string DEFAULT_CAR_WINTER_REPLACE_MM = '4.000';
    public const string DEFAULT_CAR_LEGAL_MM = '1.600';
    public const string DEFAULT_BIKE_REPLACE_MM = '2.000';
    public const string DEFAULT_BIKE_LEGAL_MM = '1.000';
    public const int DEFAULT_AGE_YEARS = 6;
    public const int MAX_AGE_YEARS = 15;

    public function __construct(
        public string $carReplaceMm = self::DEFAULT_CAR_REPLACE_MM,
        public string $carWinterReplaceMm = self::DEFAULT_CAR_WINTER_REPLACE_MM,
        public string $carLegalMm = self::DEFAULT_CAR_LEGAL_MM,
        public string $bikeReplaceMm = self::DEFAULT_BIKE_REPLACE_MM,
        public string $bikeLegalMm = self::DEFAULT_BIKE_LEGAL_MM,
        /** 0 = off. */
        public int $ageYears = self::DEFAULT_AGE_YEARS,
    ) {
    }

    /**
     * The depth to replace a tyre at: a car's winter tyres have their own.
     */
    public function replaceAt(VehicleType $type, ?TyreSeason $season): string
    {
        return match ($type) {
            VehicleType::Car => $season === TyreSeason::Winter ? $this->carWinterReplaceMm : $this->carReplaceMm,
            VehicleType::Bike => $this->bikeReplaceMm,
        };
    }

    /**
     * When a tyre made on $made reaches the age limit: that date + N years,
     * clamped like maintenance intervals (29 Feb → 28 Feb); null without a
     * date or with the limit off.
     */
    public function ageLimitOn(?DateTimeImmutable $made): ?DateTimeImmutable
    {
        return $made === null || $this->ageYears === 0 ? null : LocalTime::addMonths($made, 12 * $this->ageYears);
    }

    public function legalMinimum(VehicleType $type): string
    {
        return match ($type) {
            VehicleType::Car => $this->carLegalMm,
            VehicleType::Bike => $this->bikeLegalMm,
        };
    }

    /**
     * From the stored setting; anything missing or out of range falls back
     * to its default, so a hand-edited row can never break the app.
     */
    public static function fromArray(mixed $value): self
    {
        $value = is_array($value) ? $value : [];
        $age = $value['age_years'] ?? null;

        return new self(
            self::depth($value['car_replace_mm'] ?? null, self::DEFAULT_CAR_REPLACE_MM),
            self::depth($value['car_winter_replace_mm'] ?? null, self::DEFAULT_CAR_WINTER_REPLACE_MM),
            self::depth($value['car_legal_mm'] ?? null, self::DEFAULT_CAR_LEGAL_MM),
            self::depth($value['bike_replace_mm'] ?? null, self::DEFAULT_BIKE_REPLACE_MM),
            self::depth($value['bike_legal_mm'] ?? null, self::DEFAULT_BIKE_LEGAL_MM),
            is_int($age) && $age >= 0 && $age <= self::MAX_AGE_YEARS ? $age : self::DEFAULT_AGE_YEARS,
        );
    }

    /**
     * @return array{car_replace_mm: string, car_winter_replace_mm: string, car_legal_mm: string,
     *     bike_replace_mm: string, bike_legal_mm: string, age_years: int}
     */
    public function toArray(): array
    {
        return [
            'car_replace_mm' => $this->carReplaceMm,
            'car_winter_replace_mm' => $this->carWinterReplaceMm,
            'car_legal_mm' => $this->carLegalMm,
            'bike_replace_mm' => $this->bikeReplaceMm,
            'bike_legal_mm' => $this->bikeLegalMm,
            'age_years' => $this->ageYears,
        ];
    }

    private static function depth(mixed $value, string $default): string
    {
        return is_string($value) && Decimal::isCanonical($value)
            && Decimal::compare($value, '0') >= 0 && Decimal::compare($value, DepthUnit::MAX_MM) <= 0
            ? Decimal::round($value, DepthUnit::MM_SCALE)
            : $default;
    }
}
