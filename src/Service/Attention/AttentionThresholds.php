<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

/**
 * A user's staleness thresholds for *Needs attention* (spec.md §7.24),
 * stored as the `attention.thresholds` user setting. A vehicle is judged by
 * its owner's, whoever looks.
 */
final readonly class AttentionThresholds
{
    public const int DEFAULT_MILEAGE_DAYS = 60;
    public const int MIN_MILEAGE_DAYS = 7;
    public const int MAX_MILEAGE_DAYS = 365;
    public const int DEFAULT_VALUATION_MONTHS = 12;
    public const int MIN_VALUATION_MONTHS = 1;
    public const int MAX_VALUATION_MONTHS = 60;

    public function __construct(
        /** *Mileage not updated after* this many days. */
        public int $mileageDays = self::DEFAULT_MILEAGE_DAYS,
        /** *Valuation is stale after* this many months. */
        public int $valuationMonths = self::DEFAULT_VALUATION_MONTHS,
    ) {
    }

    /**
     * From the stored setting; anything missing or out of range falls back
     * to the default, so a hand-edited row can never break the app.
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
        );
    }

    /**
     * @return array{mileage_days: int, valuation_months: int}
     */
    public function toArray(): array
    {
        return ['mileage_days' => $this->mileageDays, 'valuation_months' => $this->valuationMonths];
    }

    private static function within(mixed $value, int $min, int $max, int $default): int
    {
        return is_int($value) && $value >= $min && $value <= $max ? $value : $default;
    }
}
