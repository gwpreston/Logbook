<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

/**
 * A user's thresholds for *Needs attention* (spec.md §7.24), stored as the
 * `attention.thresholds` user setting: the two staleness limits (Phase 24)
 * and the trend and cost checks' (Phase 25). A vehicle is judged by its
 * owner's, whoever looks.
 */
final readonly class AttentionThresholds
{
    public const int DEFAULT_MILEAGE_DAYS = 60;
    public const int MIN_MILEAGE_DAYS = 7;
    public const int MAX_MILEAGE_DAYS = 365;
    public const int DEFAULT_VALUATION_MONTHS = 12;
    public const int MIN_VALUATION_MONTHS = 1;
    public const int MAX_VALUATION_MONTHS = 60;
    public const int DEFAULT_DRIFT_PERCENT = 10;
    public const int DEFAULT_DRIFT_PERCENT_ELECTRIC = 15;
    public const int MIN_DRIFT_PERCENT = 5;
    public const int MAX_DRIFT_PERCENT = 50;
    public const int DEFAULT_PRICE_PERCENT = 35;
    public const int MIN_PRICE_PERCENT = 10;
    public const int MAX_PRICE_PERCENT = 90;
    public const int DEFAULT_COST_MULTIPLE = 3;
    public const int MIN_COST_MULTIPLE = 2;
    public const int MAX_COST_MULTIPLE = 20;
    public const int DEFAULT_COST_FLOOR = 100;
    public const int MIN_COST_FLOOR = 0;
    public const int MAX_COST_FLOOR = 10000;

    public function __construct(
        /** *Mileage not updated after* this many days. */
        public int $mileageDays = self::DEFAULT_MILEAGE_DAYS,
        /** *Valuation is stale after* this many months. */
        public int $valuationMonths = self::DEFAULT_VALUATION_MONTHS,
        /** *Economy drift*: this many percent worse (liquid fuel). */
        public int $driftPercent = self::DEFAULT_DRIFT_PERCENT,
        /** *Economy drift, electricity*. */
        public int $driftPercentElectric = self::DEFAULT_DRIFT_PERCENT_ELECTRIC,
        /** *Fuel price differs by* this many percent from the median. */
        public int $pricePercent = self::DEFAULT_PRICE_PERCENT,
        /** *Cost is more than* this many times the median … */
        public int $costMultiple = self::DEFAULT_COST_MULTIPLE,
        /** … *and at least* this much above it (major currency units). */
        public int $costFloor = self::DEFAULT_COST_FLOOR,
    ) {
    }

    /**
     * From the stored setting; anything missing or out of range falls back
     * to the default, so a hand-edited row (or one saved before Phase 25)
     * can never break the app.
     */
    public static function fromArray(mixed $value): self
    {
        $value = is_array($value) ? $value : [];

        return new self(
            self::within(
                $value['mileage_days'] ?? null,
                self::MIN_MILEAGE_DAYS,
                self::MAX_MILEAGE_DAYS,
                self::DEFAULT_MILEAGE_DAYS,
            ),
            self::within(
                $value['valuation_months'] ?? null,
                self::MIN_VALUATION_MONTHS,
                self::MAX_VALUATION_MONTHS,
                self::DEFAULT_VALUATION_MONTHS,
            ),
            self::within(
                $value['drift_percent'] ?? null,
                self::MIN_DRIFT_PERCENT,
                self::MAX_DRIFT_PERCENT,
                self::DEFAULT_DRIFT_PERCENT,
            ),
            self::within(
                $value['drift_percent_electric'] ?? null,
                self::MIN_DRIFT_PERCENT,
                self::MAX_DRIFT_PERCENT,
                self::DEFAULT_DRIFT_PERCENT_ELECTRIC,
            ),
            self::within(
                $value['price_percent'] ?? null,
                self::MIN_PRICE_PERCENT,
                self::MAX_PRICE_PERCENT,
                self::DEFAULT_PRICE_PERCENT,
            ),
            self::within(
                $value['cost_multiple'] ?? null,
                self::MIN_COST_MULTIPLE,
                self::MAX_COST_MULTIPLE,
                self::DEFAULT_COST_MULTIPLE,
            ),
            self::within(
                $value['cost_floor'] ?? null,
                self::MIN_COST_FLOOR,
                self::MAX_COST_FLOOR,
                self::DEFAULT_COST_FLOOR,
            ),
        );
    }

    /**
     * @return array{mileage_days: int, valuation_months: int, drift_percent: int, drift_percent_electric: int, price_percent: int, cost_multiple: int, cost_floor: int}
     */
    public function toArray(): array
    {
        return [
            'mileage_days' => $this->mileageDays,
            'valuation_months' => $this->valuationMonths,
            'drift_percent' => $this->driftPercent,
            'drift_percent_electric' => $this->driftPercentElectric,
            'price_percent' => $this->pricePercent,
            'cost_multiple' => $this->costMultiple,
            'cost_floor' => $this->costFloor,
        ];
    }

    private static function within(mixed $value, int $min, int $max, int $default): int
    {
        return is_int($value) && $value >= $min && $value <= $max ? $value : $default;
    }
}
