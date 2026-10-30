<?php

declare(strict_types=1);

namespace Logbook\Support\Number;

/**
 * A running total of canonical decimals: the same as adding them one by
 * one with Decimal::add() starting from '0' (the total has as many places
 * as the value with the most), but kept as an integer until it is read. A
 * fuel history adds up thousands of fill-ups (Phase 54.5, K149). A total
 * that grows too large for that carries on with Decimal::add().
 */
final class DecimalSum
{
    /** Kept in an integer while under this: well inside a PHP int, however large the next value. */
    private const int LIMIT = 10 ** 17;

    private int $scaled = 0;
    private int $scale = 0;
    /** The total as a string, once it has left the integers. */
    private ?string $big = null;

    public function add(string $value): void
    {
        if ($this->big === null) {
            $dot = strpos($value, '.');
            $places = $dot === false ? 0 : strlen($value) - $dot - 1;
            $shift = $places - $this->scale;
            if ($shift > 17 || ($shift > 0 && abs($this->scaled) >= intdiv(self::LIMIT, 10 ** $shift))) {
                $this->big = $this->value();
            } elseif ($shift > 0) {
                $this->scaled *= 10 ** $shift;
                $this->scale = $places;
            }
        }
        if ($this->big !== null) {
            $this->big = Decimal::add($this->big, $value);

            return;
        }

        $this->scaled += Decimal::toScaledInt($value, $this->scale);
        if (abs($this->scaled) >= self::LIMIT) {
            $this->big = Decimal::fromScaledInt($this->scaled, $this->scale);
        }
    }

    public function value(): string
    {
        return $this->big ?? Decimal::fromScaledInt($this->scaled, $this->scale);
    }
}
