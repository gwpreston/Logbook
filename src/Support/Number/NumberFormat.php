<?php

declare(strict_types=1);

namespace Logbook\Support\Number;

use NumberFormatter;

/**
 * How the numbers in an imported file are written (spec.md §7.13, K1,
 * #573). A space as thousands separator is read in both.
 */
enum NumberFormat: string
{
    /** 1,234.56 */
    case DotDecimal = 'dot';
    /** 1.234,56 */
    case CommaDecimal = 'comma';

    /**
     * The locale's own format: comma decimal where the locale writes a
     * decimal comma (German, French), else dot decimal.
     */
    public static function forLocale(string $locale): self
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);

        return $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) === ','
            ? self::CommaDecimal
            : self::DotDecimal;
    }

    /** @return non-empty-string */
    public function decimalSeparator(): string
    {
        return $this === self::DotDecimal ? '.' : ',';
    }

    public function groupingSeparator(): string
    {
        return $this === self::DotDecimal ? ',' : '.';
    }
}
