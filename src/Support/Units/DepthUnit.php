<?php

declare(strict_types=1);

namespace Logbook\Support\Units;

use Logbook\Support\Number\Decimal;
use NumberFormatter;

/**
 * Display unit for tread depth (spec.md §8, §7.17). Storage is always
 * millimetres with three decimals, so a depth typed in 32nds of an inch
 * converts back unchanged: 10/32″ is stored as 7.938 mm and shows as 10/32″.
 * This is the only place depths are converted, parsed or formatted.
 */
enum DepthUnit: string
{
    /** 1/32″ in millimetres, exact (25.4 / 32). */
    public const string MM_PER_32ND = '0.79375';
    /** Stored scale: two places would drift by a 32nd after a few edits. */
    public const int MM_SCALE = 3;
    /** The deepest depth accepted, in millimetres. */
    public const string MAX_MM = '20';

    case Millimetre = 'mm';
    case ThirtySecond = 'in32';

    /**
     * Places a typed depth may have: 0.01 mm, or halves of a 32nd.
     */
    public function inputScale(): int
    {
        return $this === self::Millimetre ? 2 : 1;
    }

    /**
     * The deepest depth that can be typed in this unit (20 mm, 25/32″).
     */
    public function max(): string
    {
        return $this === self::Millimetre ? self::MAX_MM : '25';
    }

    /**
     * What is wrong with a depth typed in this unit (canonical decimal), as
     * a translation key, or null: it must be 0 to max() and, in 32nds, a
     * whole or half 32nd.
     */
    public function problem(string $value): ?string
    {
        if (!Decimal::isCanonical($value)) {
            return 'validation.number';
        }
        if (Decimal::compare($value, '0') < 0 || Decimal::compare($value, $this->max()) > 0) {
            return 'tyre.error.depth_range';
        }

        return $this === self::ThirtySecond && !self::isHalf($value) ? 'tyre.error.depth_halves' : null;
    }

    /**
     * A canonical decimal in this unit → millimetres (3 places).
     */
    public function toMm(string $value): string
    {
        return match ($this) {
            self::Millimetre => Decimal::round($value, self::MM_SCALE),
            self::ThirtySecond => Decimal::multiply($value, self::MM_PER_32ND, self::MM_SCALE),
        };
    }

    /**
     * Millimetres → this unit as typed back into a form: "4.2" (mm, up to
     * two places, trimmed), "6.5" (32nds, to the nearest half).
     */
    public function toInput(string $mm): string
    {
        return match ($this) {
            self::Millimetre => Decimal::trim(Decimal::round($mm, 2)),
            self::ThirtySecond => Decimal::trim(Decimal::fromScaledInt($this->halves($mm) * 5, 1)),
        };
    }

    /**
     * The number part of a displayed depth: "4.2" / "4,2" (mm, one decimal,
     * in the locale's format), "6" or "6½" (32nds: locale-neutral).
     */
    public function formatNumber(string $mm, string $locale): string
    {
        if ($this === self::Millimetre) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 1);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);
            $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);

            return (string) $formatter->format((float) Decimal::round($mm, 1));
        }

        $halves = $this->halves($mm);

        return intdiv($halves, 2) . ($halves % 2 === 1 ? '½' : '');
    }

    /**
     * Millimetres in half-32nds, rounded to the nearest (half up).
     */
    private function halves(string $mm): int
    {
        $halves = Decimal::divide(Decimal::multiply($mm, '2', 6), self::MM_PER_32ND, 0);

        return (int) $halves;
    }

    private static function isHalf(string $value): bool
    {
        return Decimal::compare(Decimal::round(Decimal::multiply($value, '2', 3), 0), Decimal::multiply($value, '2', 3)) === 0;
    }
}
