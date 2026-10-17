<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use DateTimeImmutable;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartCondition;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Domain\Part\WarrantyStart;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The part forms (spec.md §7.39): the part itself (also an inline row on a
 * record's form, under a field prefix), *Fit* and *Remove*. Distances are
 * in the user's unit; no date may be after the user's today.
 */
final class PartForm
{
    public const int NAME_MAX = 120;
    public const int CODE_MAX = 80;
    public const int SUPPLIER_MAX = 120;
    public const int PROVIDER_MAX = 120;
    public const int TERMS_MAX = 500;
    public const int NOTES_MAX = 2000;
    public const int MONEY_SCALE = 3;
    public const int MONEY_WHOLE_DIGITS = 11;
    public const int WARRANTY_MONTHS_MAX = 600;

    /**
     * A part as the edit form shows it.
     *
     * @return array<string, string>
     */
    public static function values(Part $part, DisplayPreferences $preferences, string $prefix = ''): array
    {
        $data = $part->data;

        return [
            $prefix . 'name' => $data->name,
            $prefix . 'brand' => $data->brand ?? '',
            $prefix . 'part_number' => $data->partNumber ?? '',
            $prefix . 'serial_number' => $data->serialNumber ?? '',
            $prefix . 'supplier' => $data->supplier ?? '',
            $prefix . 'bought_on' => $data->boughtOn?->format('Y-m-d') ?? '',
            $prefix . 'price' => $data->price === null ? '' : Decimal::trim($data->price),
            $prefix . 'condition' => $data->condition->value,
            $prefix . 'warranty_months' => $data->warrantyMonths === null ? '' : (string) $data->warrantyMonths,
            $prefix . 'warranty_distance' => $data->warrantyKm === null
                ? ''
                : OdometerReadingForm::distanceForDisplay($data->warrantyKm, $preferences),
            $prefix . 'warranty_from' => $data->warrantyFrom->value ?? '',
            $prefix . 'warranty_provider' => $data->warrantyProvider ?? '',
            $prefix . 'warranty_terms' => $data->warrantyTerms ?? '',
            $prefix . 'warranty_reminder' => $data->warrantyReminder ? '1' : '',
            $prefix . 'notes' => $data->notes ?? '',
        ];
    }

    /**
     * The part's fields, read from $validator under $prefix (`new_0_` for an
     * inline row). Returns null (and leaves errors on the validator) when
     * they don't validate.
     */
    public static function read(
        Validator $validator,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        string $prefix = '',
        ?Part $stored = null,
    ): ?PartData {
        $name = $validator->string($prefix . 'name', true, self::NAME_MAX);
        $brand = $validator->string($prefix . 'brand', false, self::CODE_MAX);
        $number = $validator->string($prefix . 'part_number', false, self::CODE_MAX);
        $serial = $validator->string($prefix . 'serial_number', false, self::CODE_MAX);
        $supplier = $validator->string($prefix . 'supplier', false, self::SUPPLIER_MAX);
        $boughtOn = $validator->date($prefix . 'bought_on');
        $price = self::money($validator, $prefix . 'price');
        $condition = $validator->enum($prefix . 'condition', PartCondition::class);
        $months = $validator->integer($prefix . 'warranty_months', false, 1, self::WARRANTY_MONTHS_MAX);
        $distance = $validator->decimal(
            $prefix . 'warranty_distance',
            false,
            OdometerReadingForm::KM_SCALE,
            '0.001',
            null,
            OdometerReadingForm::MAX_WHOLE_DIGITS,
        );
        $from = $validator->enum($prefix . 'warranty_from', WarrantyStart::class);
        $provider = $validator->string($prefix . 'warranty_provider', false, self::PROVIDER_MAX);
        $terms = $validator->string($prefix . 'warranty_terms', false, self::TERMS_MAX);
        $notes = $validator->string($prefix . 'notes', false, self::NOTES_MAX);
        $remind = $validator->checkbox($prefix . 'warranty_reminder');

        if ($boughtOn !== null && $boughtOn > $today) {
            $validator->addError($prefix . 'bought_on', 'part.error.future');
        }
        // #446: nothing to remind of once a warranty has ended. Only a time limit counted from the
        // purchase date can be told here; any other warranty simply stops reminding when it ends.
        // An edit that leaves a tick already stored, and the warranty, as they were is not refused.
        $old = $stored?->data;
        $unchanged = $old !== null && $old->warrantyReminder
            && $old->warrantyMonths === $months
            && $old->boughtOn?->format('Y-m-d') === $boughtOn?->format('Y-m-d')
            && $old->warrantyFrom === ($from ?? self::defaultStart($boughtOn));
        if (
            $remind && !$unchanged && $months !== null && $boughtOn !== null
            && ($from ?? self::defaultStart($boughtOn)) === WarrantyStart::Purchase
            && LocalTime::addMonths($boughtOn, $months) < $today
        ) {
            $validator->addError($prefix . 'warranty_reminder', 'part.error.warranty_ended');
        }
        if ($name === null || !$validator->errors()->isEmpty()) {
            return null;
        }
        $warranty = $months !== null || $distance !== null;

        return new PartData(
            name: $name,
            brand: $brand,
            partNumber: $number,
            serialNumber: $serial,
            supplier: $supplier,
            boughtOn: $boughtOn,
            price: $price,
            condition: $condition ?? PartCondition::New,
            warrantyMonths: $months,
            warrantyKm: $distance === null
                ? null
                : OdometerReadingForm::distanceToKm($distance, $preferences, $stored?->data->warrantyKm),
            // #427: from the purchase date when there is one, else the fitting.
            warrantyFrom: $warranty ? ($from ?? self::defaultStart($boughtOn)) : null,
            warrantyProvider: $provider,
            warrantyTerms: $terms,
            notes: $notes,
            warrantyReminder: $remind && $warranty,
        );
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        ?Part $stored = null,
    ): PartData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        $data = self::read($validator, $preferences, $today, '', $stored);

        return $data ?? $validator->errors();
    }

    /**
     * *Fit* / *Fit again* (and *Already on the car* on the add form): a
     * date, not after today and not before the part last came off, and an
     * optional mileage.
     *
     * @param array<array-key, mixed> $input
     * @return array{on: DateTimeImmutable, km: ?string}|ValidationErrors
     */
    public static function parseFit(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        string $prefix = 'fit_',
    ): array|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        $on = $validator->date($prefix . 'on', true);
        $km = self::odometer($validator, $prefix . 'odometer');
        if ($on !== null && $on > $today) {
            $validator->addError($prefix . 'on', 'part.error.future');
        }
        if ($on === null || !$validator->errors()->isEmpty()) {
            return $validator->errors();
        }

        return ['on' => $on, 'km' => $km === null ? null : OdometerReadingForm::distanceToKm($km, $preferences)];
    }

    /**
     * *Remove*: a date, a mileage and the outcome (a sale price only when sold).
     *
     * @param array<array-key, mixed> $input
     * @return array{on: DateTimeImmutable, km: ?string, removal: PartRemoval}|ValidationErrors
     */
    public static function parseRemove(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
    ): array|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        $on = $validator->date('removed_on', true);
        $km = self::odometer($validator, 'odometer');
        $removal = self::removal($validator, '');
        if ($on !== null && $on > $today) {
            $validator->addError('removed_on', 'part.error.future');
        }
        if ($on === null || $removal === null || !$validator->errors()->isEmpty()) {
            return $validator->errors();
        }

        return [
            'on' => $on,
            'km' => $km === null ? null : OdometerReadingForm::distanceToKm($km, $preferences),
            'removal' => $removal,
        ];
    }

    /**
     * An outcome and, only when sold, its price (`{prefix}outcome`,
     * `{prefix}sold_price`). A sale with no price stays without one (K5):
     * 0 is a price someone typed, not "not given".
     */
    public static function removal(Validator $validator, string $prefix): ?PartRemoval
    {
        $outcome = $validator->enum($prefix . 'outcome', RemovalOutcome::class) ?? RemovalOutcome::Kept;
        $price = self::money($validator, $prefix . 'sold_price');
        if ($price !== null && $outcome !== RemovalOutcome::Sold) {
            $validator->addError($prefix . 'sold_price', 'part.error.price_only_sold');

            return null;
        }

        return new PartRemoval($outcome, $price);
    }

    private static function defaultStart(?DateTimeImmutable $boughtOn): WarrantyStart
    {
        return $boughtOn !== null ? WarrantyStart::Purchase : WarrantyStart::Fitting;
    }

    public static function money(Validator $validator, string $field): ?string
    {
        return $validator->decimal($field, false, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
    }

    public static function odometer(Validator $validator, string $field): ?string
    {
        return $validator->decimal(
            $field,
            false,
            OdometerReadingForm::KM_SCALE,
            '0',
            null,
            OdometerReadingForm::MAX_WHOLE_DIGITS,
        );
    }
}
