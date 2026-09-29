<?php

declare(strict_types=1);

namespace Logbook\Service\Valuation;

use DateTimeImmutable;
use IntlDateFormatter;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit valuation form ↔ VehicleValuationData (spec.md §7.1). A date and
 * an amount are required; 0 is valid (a write-off or scrap value). The date
 * falls between the purchase (when set) and today in the owner's time zone,
 * and never after the sale: the sale price is a sold vehicle's final value.
 */
final class ValuationForm
{
    public const int SOURCE_MAX = 100;
    public const int NOTES_MAX = 500;
    /** decimal(14,3) money column. */
    public const int MONEY_WHOLE_DIGITS = 11;
    public const int MONEY_SCALE = 3;

    /**
     * @return array<string, string>
     */
    public static function values(VehicleValuation $valuation): array
    {
        $data = $valuation->data;

        return [
            'valued_on' => $data->valuedOn->format('Y-m-d'),
            'amount' => Decimal::trim($data->amount),
            'source' => $data->source ?? '',
            'notes' => $data->notes ?? '',
        ];
    }

    /**
     * @param DateTimeImmutable $today calendar date
     * @return array<string, string>
     */
    public static function defaults(DateTimeImmutable $today): array
    {
        return ['valued_on' => $today->format('Y-m-d')];
    }

    /**
     * @param array<array-key, mixed> $input
     * @param DateTimeImmutable $today calendar date in the owner's time zone
     */
    public static function parse(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        Vehicle $vehicle,
    ): VehicleValuationData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);

        $valuedOn = $validator->date('valued_on', true);
        $amount = $validator->decimal('amount', true, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
        $source = $validator->string('source', false, self::SOURCE_MAX);
        $notes = $validator->string('notes', false, self::NOTES_MAX);

        if ($valuedOn !== null) {
            $purchased = $vehicle->data->purchaseDate;
            $sold = $vehicle->data->saleDate;
            if ($valuedOn > $today) {
                $validator->addError('valued_on', 'valuation.error.future');
            } elseif ($sold !== null && $valuedOn > $sold) {
                $validator->addError('valued_on', 'valuation.error.after_sale', [
                    'date' => self::date($sold, $preferences->locale),
                ]);
            } elseif ($purchased !== null && $valuedOn < $purchased) {
                $validator->addError('valued_on', 'valuation.error.before_purchase');
            }
        }

        if (!$validator->errors()->isEmpty() || $valuedOn === null || $amount === null) {
            return $validator->errors();
        }

        return new VehicleValuationData(
            valuedOn: $valuedOn,
            amount: $amount,
            source: $source,
            notes: $notes,
        );
    }

    /**
     * A calendar date as DisplayFormatter::date() shows it, for a message.
     */
    private static function date(DateTimeImmutable $date, string $locale): string
    {
        $formatted = (new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC'))
            ->format($date);

        return is_string($formatted) ? $formatted : $date->format('Y-m-d');
    }
}
