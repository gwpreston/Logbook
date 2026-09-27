<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Support\Number\Decimal;

/**
 * Volume, price per unit and total of a fill-up: give any two and the third
 * is derived, exactly (no floats), in whatever units they were typed in.
 *
 *  - volume × price → total, rounded to the currency's minor unit (a pump
 *    shows £65.83, not £65.8301);
 *  - total ÷ volume → price, to 6 places;
 *  - total ÷ price  → volume, to 3 places (the price must not be zero).
 *
 * When all three are given they are kept as entered: a loyalty discount
 * legitimately makes the total differ from volume × pump price, and the
 * total is what the money figures use.
 */
final readonly class FuelAmounts
{
    public const int VOLUME_SCALE = 3;
    public const int PRICE_SCALE = 6;
    public const string NEED_TWO = 'need_two';
    public const string VOLUME_UNKNOWN = 'volume_unknown';

    private function __construct(
        public string $volume,
        public string $pricePerUnit,
        public string $total,
    ) {
    }

    /**
     * @param string|null $volume canonical decimal, more than zero
     * @param string|null $pricePerUnit canonical decimal, zero or more
     * @param string|null $total canonical decimal, zero or more
     * @param int $moneyScale places for a derived total (the currency's minor unit)
     * @return self|string the completed amounts, or why not: self::NEED_TWO
     *                     (fewer than two given) or self::VOLUME_UNKNOWN (a
     *                     zero price and a total say nothing about the volume)
     */
    public static function complete(?string $volume, ?string $pricePerUnit, ?string $total, int $moneyScale): self|string
    {
        $given = count(array_filter([$volume, $pricePerUnit, $total], static fn (?string $v): bool => $v !== null));
        if ($given < 2) {
            return self::NEED_TWO;
        }

        if ($volume !== null && $pricePerUnit !== null && $total !== null) {
            return new self($volume, $pricePerUnit, $total);
        }

        if ($volume !== null && $pricePerUnit !== null) {
            return new self($volume, $pricePerUnit, Decimal::multiply($volume, $pricePerUnit, $moneyScale));
        }

        if ($volume !== null && $total !== null) {
            return new self($volume, Decimal::divide($total, $volume, self::PRICE_SCALE), $total);
        }

        assert($pricePerUnit !== null && $total !== null);
        if (Decimal::compare($pricePerUnit, '0') <= 0) {
            return self::VOLUME_UNKNOWN;
        }
        $derived = Decimal::divide($total, $pricePerUnit, self::VOLUME_SCALE);
        if (Decimal::compare($derived, '0') <= 0) {
            return self::VOLUME_UNKNOWN;
        }

        return new self($derived, $pricePerUnit, $total);
    }
}
