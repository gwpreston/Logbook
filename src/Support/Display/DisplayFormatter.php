<?php

declare(strict_types=1);

namespace Logbook\Support\Display;

use DateTimeInterface;
use IntlDateFormatter;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Currency;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use NumberFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formats values for display in the current request's preferences
 * (DisplayContext): locale-aware numbers via intl, units converted from the
 * canonical SI storage, money in its currency, dates in the user's zone.
 *
 * Every method accepts null and returns '' for it, so templates can decide
 * how to show a missing value.
 */
final readonly class DisplayFormatter
{
    public function __construct(
        private DisplayContext $context,
        private TranslatorInterface $translator,
    ) {
    }

    public function number(int|float|string|null $value, int $maxFractionDigits = 2, int $minFractionDigits = 0): string
    {
        $number = self::toFloat($value);
        if ($number === null) {
            return '';
        }

        $formatter = new NumberFormatter($this->locale(), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $minFractionDigits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits);
        $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);

        return (string) $formatter->format($number);
    }

    /**
     * @param Money|string|null $amount a Money, or a canonical decimal in $currency
     * @param string|null $currency defaults to the user's currency
     */
    public function money(Money|string|null $amount, ?string $currency = null): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        $money = $amount instanceof Money
            ? $amount
            : Money::of($amount, $currency ?? $this->context->preferences()->currency);

        $formatter = new NumberFormatter($this->locale(), NumberFormatter::CURRENCY);
        $digits = Currency::fractionDigits($money->currency);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);

        return (string) $formatter->formatCurrency($money->toFloat(), $money->currency);
    }

    /**
     * A distance stored in km, in the user's distance unit: "12,345 mi".
     */
    public function distance(int|float|string|null $km, int $decimals = 0): string
    {
        $value = self::toFloat($km);
        if ($value === null) {
            return '';
        }

        $unit = $this->context->preferences()->distanceUnit;

        return $this->translator->trans('units.distance.' . $unit->value, [
            'value' => $this->number($unit->fromKm($value), $decimals, $decimals),
        ]);
    }

    /**
     * A volume stored in litres, in the user's volume unit: "45.2 L".
     */
    public function volume(int|float|string|null $litres, int $maxDecimals = 2): string
    {
        $value = self::toFloat($litres);
        if ($value === null) {
            return '';
        }

        $unit = $this->context->preferences()->volumeUnit;

        return $this->translator->trans('units.volume.' . $unit->value, [
            'value' => $this->number($unit->fromLitres($value), $maxDecimals),
        ]);
    }

    /**
     * Battery capacity or electrical energy: "58 kWh".
     */
    public function energy(int|float|string|null $kwh, int $maxDecimals = 1): string
    {
        $value = self::toFloat($kwh);
        if ($value === null) {
            return '';
        }

        return $this->translator->trans('units.energy.kwh', ['value' => $this->number($value, $maxDecimals)]);
    }

    /**
     * Consumption over a distance, in the user's consumption unit: "48.7 mpg".
     */
    public function consumption(int|float|string|null $km, int|float|string|null $litres, int $decimals = 1): string
    {
        $distance = self::toFloat($km);
        $volume = self::toFloat($litres);
        if ($distance === null || $volume === null) {
            return '';
        }

        $unit = $this->context->preferences()->consumptionUnit;
        $value = $unit->fromDistanceAndVolume($distance, $volume);
        if ($value === null) {
            return '';
        }

        return $this->translator->trans('units.consumption.' . $unit->value, [
            'value' => $this->number($value, $decimals, $decimals),
        ]);
    }

    /**
     * A calendar date (no time, no zone): shown exactly as stored.
     */
    public function date(?DateTimeInterface $date, int $style = IntlDateFormatter::MEDIUM): string
    {
        if ($date === null) {
            return '';
        }

        return $this->formatDate($date, $style, IntlDateFormatter::NONE, 'UTC');
    }

    /**
     * An instant (stored in UTC), shown in the user's time zone.
     */
    public function dateTime(?DateTimeInterface $instant, int $dateStyle = IntlDateFormatter::MEDIUM): string
    {
        if ($instant === null) {
            return '';
        }

        $preferences = $this->context->preferences();
        $local = LocalTime::fromUtc($instant, $preferences->timeZone());

        return $this->formatDate($local, $dateStyle, IntlDateFormatter::SHORT, $preferences->timezone);
    }

    /**
     * The day on which an instant (stored in UTC) fell in the user's time
     * zone, without the time: 23:30 UTC on 1 Jan is "2 Jan" in Auckland.
     */
    public function instantDate(?DateTimeInterface $instant, int $style = IntlDateFormatter::MEDIUM): string
    {
        if ($instant === null) {
            return '';
        }

        $preferences = $this->context->preferences();
        $local = LocalTime::fromUtc($instant, $preferences->timeZone());

        return $this->formatDate($local, $style, IntlDateFormatter::NONE, $preferences->timezone);
    }

    private function formatDate(DateTimeInterface $value, int $dateStyle, int $timeStyle, string $zone): string
    {
        $formatter = new IntlDateFormatter($this->locale(), $dateStyle, $timeStyle, $zone);
        $formatted = $formatter->format($value);

        return is_string($formatted) ? $formatted : $value->format('Y-m-d H:i');
    }

    private function locale(): string
    {
        return $this->context->preferences()->locale;
    }

    private static function toFloat(int|float|string|null $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return Decimal::isCanonical($value) ? (float) $value : null;
        }

        return (float) $value;
    }
}
