<?php

declare(strict_types=1);

namespace Logbook\Support\Money;

use InvalidArgumentException;
use Logbook\Support\Number\Decimal;

/**
 * An amount of money in one currency.
 *
 * Held as an integer number of millionths of the major unit, so arithmetic is
 * exact and no float is ever involved in storing or adding amounts (floats
 * are only produced for display formatting). Six places covers every
 * currency's minor unit plus the extra precision of unit prices such as a
 * fuel price per litre. Zero is a perfectly valid amount.
 */
final readonly class Money
{
    public const int SCALE = 6;

    private function __construct(
        public int $micros,
        public string $currency,
    ) {
    }

    /**
     * @param string $amount canonical decimal, e.g. "12.50" (see Support\Number\DecimalParser)
     */
    public static function of(string $amount, string $currency): self
    {
        return new self(Decimal::toScaledInt($amount, self::SCALE), self::checkedCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::checkedCurrency($currency));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->micros + $other->micros, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->micros - $other->micros, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->micros === 0;
    }

    public function isNegative(): bool
    {
        return $this->micros < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->micros === $other->micros;
    }

    /**
     * Canonical decimal rounded to $scale places (half away from zero), for
     * storage in a DECIMAL column: Money::of('12.5', 'GBP')->toDecimal(3) = "12.500".
     */
    public function toDecimal(int $scale): string
    {
        return Decimal::round(Decimal::fromScaledInt($this->micros, self::SCALE), $scale);
    }

    /**
     * For display formatting only (intl takes a float). Never store this.
     */
    public function toFloat(): float
    {
        return $this->micros / 10 ** self::SCALE;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine %s with %s amounts.',
                $this->currency,
                $other->currency,
            ));
        }
    }

    private static function checkedCurrency(string $currency): string
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not an ISO 4217 currency code.', $currency));
        }

        return $currency;
    }
}
