<?php

declare(strict_types=1);

namespace Logbook\Support\Display;

use IntlDateFormatter;
use IntlDatePatternGenerator;
use NumberFormatter;

/**
 * intl formatters, made once per locale and settings and then reused.
 * Making a number formatter costs about thirty times what formatting with
 * it does, and a date pattern generator far more; a report formats
 * thousands of numbers and hundreds of dates (Phase 54.5, K150). A
 * formatter's settings are fixed when it is made, so a reused one formats
 * exactly as a new one would.
 */
final class IntlFormatters
{
    /** @var array<string, NumberFormatter> */
    private array $numbers = [];

    /** @var array<string, IntlDateFormatter> */
    private array $dates = [];

    /** @var array<string, string|false> */
    private array $patterns = [];

    /**
     * Rounding half up, as every figure is shown; a null $minDigits keeps the style's own.
     */
    public function number(string $locale, int $style, int $maxDigits, ?int $minDigits = null): NumberFormatter
    {
        $key = $locale . '|' . $style . '|' . $maxDigits . '|' . ($minDigits ?? '-');
        if (!isset($this->numbers[$key])) {
            $formatter = new NumberFormatter($locale, $style);
            if ($minDigits !== null) {
                $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $minDigits);
            }
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxDigits);
            $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);
            $this->numbers[$key] = $formatter;
        }

        return $this->numbers[$key];
    }

    public function date(
        string $locale,
        int $dateStyle,
        int $timeStyle,
        string $zone,
        ?string $pattern = null,
    ): IntlDateFormatter {
        $key = $locale . '|' . $dateStyle . '|' . $timeStyle . '|' . $zone . '|' . ($pattern ?? '');
        if (!isset($this->dates[$key])) {
            $this->dates[$key] = new IntlDateFormatter($locale, $dateStyle, $timeStyle, $zone, null, $pattern);
        }

        return $this->dates[$key];
    }

    /**
     * The locale's pattern for an ICU skeleton ("dMMM" → "d MMM"), or
     * $fallback when it has none.
     */
    public function pattern(string $locale, string $skeleton, string $fallback): string
    {
        $key = $locale . '|' . $skeleton;
        if (!array_key_exists($key, $this->patterns)) {
            $pattern = IntlDatePatternGenerator::create($locale)?->getBestPattern($skeleton);
            $this->patterns[$key] = is_string($pattern) && $pattern !== '' ? $pattern : false;
        }

        return $this->patterns[$key] === false ? $fallback : $this->patterns[$key];
    }
}
