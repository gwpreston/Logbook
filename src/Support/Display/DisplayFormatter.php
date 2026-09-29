<?php

declare(strict_types=1);

namespace Logbook\Support\Display;

use DateTimeInterface;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Currency;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\ElectricEfficiencyUnit;
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

        $digits = Currency::fractionDigits($money->currency);

        return $this->formatMoney($money->toFloat(), $money->currency, $digits, $digits);
    }

    /**
     * A price per litre (or per kWh for electricity), in the user's volume
     * unit, with one more decimal than the currency normally uses:
     * "£1.459/L", "$3.499/US gal", "£0.245/kWh".
     *
     * @param string|null $perUnit canonical decimal per litre (or kWh)
     */
    public function unitPrice(?string $perUnit, string $currency, bool $electric): string
    {
        if ($perUnit === null || !Decimal::isCanonical($perUnit)) {
            return '';
        }

        $digits = Currency::fractionDigits($currency);
        if ($electric) {
            $price = $this->formatMoney((float) $perUnit, $currency, $digits, $digits + 1);

            return $this->translator->trans('units.price.kwh', ['price' => $price]);
        }

        $unit = $this->context->preferences()->volumeUnit;
        $price = $this->formatMoney((float) $unit->pricePerUnit($perUnit, 6), $currency, $digits, $digits + 1);

        return $this->translator->trans('units.price.' . $unit->value, ['price' => $price]);
    }

    /**
     * A cost per kilometre, per the user's distance unit: "£0.123/mi".
     *
     * @param string|null $perKm canonical decimal
     */
    public function perDistance(?string $perKm, string $currency): string
    {
        if ($perKm === null || !Decimal::isCanonical($perKm)) {
            return '';
        }

        $unit = $this->context->preferences()->distanceUnit;
        $value = $unit === DistanceUnit::Mile ? (float) $perKm * DistanceUnit::KM_PER_MILE : (float) $perKm;
        $digits = Currency::fractionDigits($currency);

        return $this->translator->trans('units.per_distance.' . $unit->value, [
            'price' => $this->formatMoney($value, $currency, $digits, $digits + 1),
        ]);
    }

    /**
     * A cost per kilometre as a cost per 1,000 of the user's distance unit,
     * for figures too small to read per km (a tyre's): "£4.90 per 1,000 mi".
     *
     * @param string|null $perKm canonical decimal
     */
    public function perThousandDistance(?string $perKm, string $currency): string
    {
        if ($perKm === null || !Decimal::isCanonical($perKm)) {
            return '';
        }

        $unit = $this->context->preferences()->distanceUnit;
        $value = ($unit === DistanceUnit::Mile ? (float) $perKm * DistanceUnit::KM_PER_MILE : (float) $perKm) * 1000;
        $digits = Currency::fractionDigits($currency);

        return $this->translator->trans('tyre.cost_per', [
            'price' => $this->formatMoney($value, $currency, $digits, $digits),
            'unit' => $unit->value,
        ]);
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
        // A stored decimal converts back exactly to what was typed (3 places)
        // before rounding, so 1,234.5 mi shows as "1,235 mi", not "1,234 mi".
        $converted = is_string($km) ? (float) $unit->fromKmDecimal($km, 3) : $unit->fromKm($value);

        return $this->translator->trans('units.distance.' . $unit->value, [
            'value' => $this->number($converted, $decimals, $decimals),
        ]);
    }

    /**
     * A distance that is only an estimate, rounded so it does not look
     * precise: to the nearest 100 from 1,000 up, else to the nearest 10
     * ("about 6,000 mi left", "about 800 mi").
     */
    public function aboutDistance(?string $km): string
    {
        if ($km === null || !Decimal::isCanonical($km)) {
            return '';
        }

        $unit = $this->context->preferences()->distanceUnit;
        $value = (float) $unit->fromKmDecimal($km, 3);
        $step = $value >= 1000 ? 100 : 10;

        return $this->translator->trans('units.distance.' . $unit->value, [
            'value' => $this->number(round($value / $step) * $step, 0),
        ]);
    }

    /**
     * A tread depth stored in millimetres, in the user's depth unit:
     * "4.2 mm", "6½/32″" (spec.md §7.17).
     */
    public function depth(?string $mm): string
    {
        if ($mm === null || !Decimal::isCanonical($mm)) {
            return '';
        }

        return (new DepthText($mm, null, $this->context->preferences()->depthUnit, $this->locale()))->trans($this->translator);
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
    public function consumption(
        int|float|string|null $km,
        int|float|string|null $litres,
        int $decimals = 1,
        ?ConsumptionUnit $unit = null,
    ): string {
        $distance = self::toFloat($km);
        $volume = self::toFloat($litres);
        if ($distance === null || $volume === null) {
            return '';
        }

        $unit ??= $this->context->preferences()->consumptionUnit;
        $value = $unit->fromDistanceAndVolume($distance, $volume);
        if ($value === null) {
            return '';
        }

        return $this->translator->trans('units.consumption.' . $unit->value, [
            'value' => $this->number($value, $decimals, $decimals),
        ]);
    }

    /**
     * Electric efficiency over a distance: kWh/100 km for kilometre users,
     * mi/kWh for mile users ("4.1 mi/kWh").
     */
    public function efficiency(
        int|float|string|null $km,
        int|float|string|null $kwh,
        int $decimals = 1,
        ?ElectricEfficiencyUnit $unit = null,
    ): string {
        $distance = self::toFloat($km);
        $energy = self::toFloat($kwh);
        if ($distance === null || $energy === null) {
            return '';
        }

        $unit ??= ElectricEfficiencyUnit::forDistanceUnit($this->context->preferences()->distanceUnit);
        $value = $unit->fromDistanceAndEnergy($distance, $energy);
        if ($value === null) {
            return '';
        }

        return $this->translator->trans('units.efficiency.' . $unit->value, [
            'value' => $this->number($value, $decimals, $decimals),
        ]);
    }

    /**
     * Consumption for liquid fuel, efficiency for electricity.
     */
    public function economy(
        int|float|string|null $km,
        int|float|string|null $volume,
        bool $electric,
        int $decimals = 1,
    ): string {
        return $electric
            ? $this->efficiency($km, $volume, $decimals)
            : $this->consumption($km, $volume, $decimals);
    }

    /**
     * Litres (in the user's volume unit), or kWh for electricity.
     */
    public function quantity(int|float|string|null $value, bool $electric, int $maxDecimals = 2): string
    {
        return $electric ? $this->energy($value, $maxDecimals) : $this->volume($value, $maxDecimals);
    }

    /**
     * A file size: "830 B", "12 KB", "3.4 MB" (binary multiples, as file
     * managers show them).
     */
    public function fileSize(?int $bytes): string
    {
        if ($bytes === null) {
            return '';
        }

        [$unit, $value, $decimals] = match (true) {
            $bytes < 1024 => ['b', $bytes, 0],
            $bytes < 1024 * 1024 => ['kb', $bytes / 1024, 0],
            default => ['mb', $bytes / (1024 * 1024), 1],
        };

        return $this->translator->trans('units.file.' . $unit, ['value' => $this->number($value, $decimals)]);
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
     * A calendar month in the user's language: "Sep 2026" (short) or
     * "September 2026", with the order and spelling the locale uses.
     */
    public function month(?DateTimeInterface $date, bool $short = true): string
    {
        if ($date === null) {
            return '';
        }

        $pattern = IntlDatePatternGenerator::create($this->locale())?->getBestPattern($short ? 'MMMy' : 'MMMMy');
        $formatter = new IntlDateFormatter(
            $this->locale(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'UTC',
            null,
            is_string($pattern) && $pattern !== '' ? $pattern : 'MMM y',
        );
        $formatted = $formatter->format($date);

        return is_string($formatted) ? $formatted : $date->format('Y-m');
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

    private function formatMoney(float $value, string $currency, int $minDigits, int $maxDigits): string
    {
        $formatter = new NumberFormatter($this->locale(), NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $minDigits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxDigits);
        $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);

        return (string) $formatter->formatCurrency($value, $currency);
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
