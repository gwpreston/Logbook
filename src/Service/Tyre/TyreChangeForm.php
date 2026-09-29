<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Tyre\DotCode;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreRetireReason;
use Logbook\Domain\Tyre\TyreSeason;
use Logbook\Domain\Tyre\TyreSetData;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The tyre forms ↔ typed input (spec.md §7.17): the six change forms, the
 * change edit form, the tyre edit form and the set form. Field names are
 * flat (`brand_fl`, `move_12`) so the forms work without JS and re-fill
 * after an error.
 *
 * Dates are calendar dates; the odometer is typed in the owner's distance
 * unit and parsed like a reading; a cost of 0 is valid.
 */
final class TyreChangeForm
{
    public const int BRAND_MAX = 60;
    public const int MODEL_MAX = 60;
    public const int SIZE_MAX = 30;
    public const int NOTES_MAX = 500;
    public const int SET_NAME_MAX = 100;
    public const int SET_LOCATION_MAX = 200;
    public const int VENDOR_MAX = MaintenanceEntryForm::VENDOR_MAX;

    /** `replace_{position}` / `action_{tyre}`: keep the tyre in storage. */
    public const string STORE = 'store';
    /** `action_{tyre}`: leave it on. */
    public const string KEEP = 'keep';
    /** `set`: a new set. */
    public const string NEW_SET = 'new';

    /**
     * A new change: today, and the latest reading as the odometer.
     *
     * @return array<string, string>
     */
    public static function defaults(DateTimeImmutable $today, ?OdometerReading $latest, DisplayPreferences $preferences): array
    {
        return [
            'done_on' => $today->format('Y-m-d'),
            'odometer' => $latest === null ? '' : OdometerReadingForm::distanceForDisplay($latest->readingKm, $preferences),
        ];
    }

    /**
     * The change edit form.
     *
     * @return array<string, string>
     */
    public static function values(TyreChange $change, DisplayPreferences $preferences): array
    {
        $data = $change->data;

        return [
            'done_on' => $data->doneOn->format('Y-m-d'),
            'odometer' => $data->odometerKm === null
                ? ''
                : OdometerReadingForm::distanceForDisplay($data->odometerKm, $preferences),
            'note' => $data->note ?? '',
            'link' => $data->maintenanceEntryId === null ? '' : (string) $data->maintenanceEntryId,
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(
        TyreChangeKind $kind,
        array $input,
        DisplayPreferences $preferences,
        TyreFormContext $context,
    ): TyreChangeInput|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        [$data, $cost] = self::common($validator, $kind, $preferences, $context);

        $new = [];
        $replaced = [];
        $positions = [];
        $removed = [];
        $into = new SetChoice();
        switch ($kind) {
            case TyreChangeKind::Existing:
                foreach (self::chosenPositions($validator, $context->positions) as $position) {
                    $tyre = self::tyreData($validator, '_' . $position->value, $context->today);
                    if ($tyre !== null) {
                        $new[] = new NewTyre($position, $tyre);
                    }
                }
                break;
            case TyreChangeKind::Fit:
                $description = self::tyreData($validator, '', $context->today, false);
                foreach (self::chosenPositions($validator, $context->positions) as $position) {
                    $dot = self::dot($validator, 'dot_' . $position->value, $context->today);
                    if ($description !== null) {
                        $new[] = new NewTyre($position, new TyreData(
                            $description->brand,
                            $description->model,
                            $description->size,
                            $description->season,
                            $dot,
                        ));
                    }
                    if (isset($context->fitted[$position->value])) {
                        $choice = self::replaceChoice($validator, 'replace_' . $position->value);
                        if ($choice !== false) {
                            $replaced[$position->value] = $choice;
                        }
                    }
                }
                break;
            case TyreChangeKind::Swap:
                $into = self::setChoice($validator, $context);
                $codes = array_map(static fn (TyrePosition $p): string => $p->value, $context->rollingPositions());
                foreach ($context->stored as $tyre) {
                    $code = $validator->choice('on_' . $tyre->id, $codes);
                    if ($code !== null) {
                        $positions[$tyre->id] = TyrePosition::from($code);
                    }
                }
                break;
            case TyreChangeKind::Rotate:
                $codes = array_map(static fn (TyrePosition $p): string => $p->value, $context->positions);
                foreach ($context->fitted as $tyre) {
                    $code = $validator->choice('move_' . $tyre->id, $codes, true);
                    if ($code !== null) {
                        $positions[$tyre->id] = TyrePosition::from($code);
                    }
                }
                break;
            case TyreChangeKind::Repair:
                foreach ($context->fitted as $tyre) {
                    if ($validator->checkbox('repair_' . $tyre->id)) {
                        $removed[$tyre->id] = null;
                    }
                }
                if ($removed === []) {
                    $validator->addError('tyres', 'tyre.error.nothing');
                }
                break;
            case TyreChangeKind::Remove:
                $into = self::setChoice($validator, $context);
                $choices = [self::KEEP, ...self::storeOrRetire()];
                foreach ($context->fitted as $tyre) {
                    $choice = $validator->choice('action_' . $tyre->id, $choices) ?? self::KEEP;
                    if ($choice !== self::KEEP) {
                        $removed[$tyre->id] = $choice === self::STORE ? null : TyreRetireReason::from($choice);
                    }
                }
                if ($removed === [] && !$validator->errors()->has('tyres')) {
                    $validator->addError('tyres', 'tyre.error.nothing');
                }
                break;
        }

        if (!$validator->errors()->isEmpty() || $data === null) {
            return $validator->errors();
        }

        return new TyreChangeInput($kind, $data, $cost, $new, $replaced, $positions, $removed, $into);
    }

    /**
     * The change edit form: date, odometer, note and link. With maintenance
     * off the link is not on the form and $currentLink is kept.
     *
     * @param array<array-key, mixed> $input
     */
    public static function parseEdit(
        TyreChangeKind $kind,
        array $input,
        DisplayPreferences $preferences,
        TyreFormContext $context,
        ?int $currentLink,
    ): TyreChangeData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        [$data] = self::common($validator, $kind, $preferences, $context, false);
        if (!$validator->errors()->isEmpty() || $data === null) {
            return $validator->errors();
        }

        return $context->maintenance
            ? $data
            : new TyreChangeData($data->doneOn, $data->odometerKm, $currentLink, $data->note);
    }

    /**
     * The tyre edit form.
     *
     * @return array<string, string>
     */
    public static function tyreValues(Tyre $tyre): array
    {
        $data = $tyre->data;

        return [
            'brand' => $data->brand ?? '',
            'model' => $data->model ?? '',
            'size' => $data->size ?? '',
            'season' => $data->season->value ?? '',
            'dot' => $data->dot->code ?? '',
            'notes' => $data->notes ?? '',
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parseTyre(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
    ): TyreData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        $data = self::tyreData($validator, '', $today);
        $notes = $validator->string('notes', false, self::NOTES_MAX);
        if (!$validator->errors()->isEmpty() || $data === null) {
            return $validator->errors();
        }

        return new TyreData($data->brand, $data->model, $data->size, $data->season, $data->dot, $notes);
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parseSet(array $input, DisplayPreferences $preferences): TyreSetData|ValidationErrors
    {
        $validator = new Validator($input, $preferences->locale);
        $name = $validator->string('name', true, self::SET_NAME_MAX);
        $location = $validator->string('storage_location', false, self::SET_LOCATION_MAX);
        $notes = $validator->string('notes', false, self::NOTES_MAX);
        if (!$validator->errors()->isEmpty() || $name === null) {
            return $validator->errors();
        }

        return new TyreSetData($name, $location, $notes);
    }

    /**
     * Date, odometer, note; and (maintenance on, for kinds that take one) the
     * cost, garage and link.
     *
     * @return array{0: ?TyreChangeData, 1: ?TyreCost}
     */
    private static function common(
        Validator $validator,
        TyreChangeKind $kind,
        DisplayPreferences $preferences,
        TyreFormContext $context,
        bool $costs = true,
    ): array {
        $doneOn = $validator->date('done_on', true);
        $link = null;
        $cost = null;
        $vendor = null;
        if ($context->maintenance) {
            $chosen = $validator->choice('link', array_map(strval(...), $context->linkIds));
            $link = $chosen === null ? null : (int) $chosen;
            if ($costs && $kind->takesCost()) {
                $cost = $validator->decimal(
                    'cost',
                    false,
                    MaintenanceEntryForm::MONEY_SCALE,
                    '0',
                    null,
                    MaintenanceEntryForm::MONEY_WHOLE_DIGITS,
                );
                $vendor = $validator->string('vendor', false, self::VENDOR_MAX);
                if (($cost !== null || $vendor !== null) && $link !== null) {
                    $validator->addError('link', 'tyre.error.cost_and_link');
                }
            }
        }
        $odometer = $validator->decimal(
            'odometer',
            $kind->requiresOdometer() && $link === null,
            OdometerReadingForm::KM_SCALE,
            '0',
            null,
            OdometerReadingForm::MAX_WHOLE_DIGITS,
        );
        $note = $validator->string('note', false, self::NOTES_MAX);

        $data = $doneOn === null ? null : new TyreChangeData(
            doneOn: $doneOn,
            odometerKm: $odometer === null
                ? null
                : $preferences->distanceUnit->toKmDecimal($odometer, OdometerReadingForm::KM_SCALE),
            maintenanceEntryId: $link,
            note: $note,
        );
        $tyreCost = $cost === null && $vendor === null
            ? null
            : new TyreCost($cost ?? '0.000', $vendor);

        return [$data, $tyreCost];
    }

    /**
     * The positions ticked (`pos_{code}`); at least one.
     *
     * @param list<TyrePosition> $positions
     * @return list<TyrePosition>
     */
    private static function chosenPositions(Validator $validator, array $positions): array
    {
        $chosen = array_values(array_filter(
            $positions,
            static fn (TyrePosition $p): bool => $validator->checkbox('pos_' . $p->value),
        ));
        if ($chosen === []) {
            $validator->addError('positions', 'tyre.error.positions');
        }

        return $chosen;
    }

    /**
     * Brand, model, size, season and (with $withDot) DOT, from fields named
     * `brand{suffix}` and so on.
     */
    private static function tyreData(
        Validator $validator,
        string $suffix,
        DateTimeImmutable $today,
        bool $withDot = true,
    ): ?TyreData {
        $before = count($validator->errors()->all());
        $brand = $validator->string('brand' . $suffix, false, self::BRAND_MAX);
        $model = $validator->string('model' . $suffix, false, self::MODEL_MAX);
        $size = $validator->string('size' . $suffix, false, self::SIZE_MAX);
        $season = $validator->enum('season' . $suffix, TyreSeason::class);
        $dot = $withDot ? self::dot($validator, 'dot' . $suffix, $today) : null;
        if (count($validator->errors()->all()) > $before) {
            return null;
        }

        return new TyreData($brand, $model, $size === null ? null : TyreData::normaliseSize($size), $season, $dot);
    }

    private static function dot(Validator $validator, string $field, DateTimeImmutable $today): ?DotCode
    {
        $raw = $validator->raw($field);
        if ($raw === '') {
            return null;
        }
        $dot = DotCode::parse($raw, $today);
        if (is_string($dot)) {
            $validator->addError($field, $dot);

            return null;
        }

        return $dot;
    }

    /**
     * What happens to a tyre a fit replaces: a retire reason, null (storage),
     * or false when the choice is missing or invalid (an error is recorded).
     */
    private static function replaceChoice(Validator $validator, string $field): TyreRetireReason|false|null
    {
        $choice = $validator->choice($field, self::storeOrRetire(), true);
        if ($choice === null) {
            return false;
        }

        return $choice === self::STORE ? null : TyreRetireReason::from($choice);
    }

    /**
     * @return list<string> `store`, then every retire reason
     */
    private static function storeOrRetire(): array
    {
        return [self::STORE, ...array_map(static fn (TyreRetireReason $r): string => $r->value, TyreRetireReason::cases())];
    }

    private static function setChoice(Validator $validator, TyreFormContext $context): SetChoice
    {
        $choice = $validator->choice('set', [self::NEW_SET, ...array_map(strval(...), $context->setIds)]);
        if ($choice === null) {
            return new SetChoice();
        }
        if ($choice !== self::NEW_SET) {
            return new SetChoice((int) $choice);
        }
        $name = $validator->string('set_name', false, self::SET_NAME_MAX);
        $location = $validator->string('set_location', false, self::SET_LOCATION_MAX);
        if ($name === null) {
            if (!$validator->errors()->has('set_name')) {
                $validator->addError('set_name', 'tyre.error.set_name');
            }

            return new SetChoice();
        }

        return new SetChoice(newSet: new TyreSetData($name, $location));
    }
}
