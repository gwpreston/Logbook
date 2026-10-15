<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use DateTimeImmutable;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The modification forms (spec.md §7.39): the modification itself and
 * *Revert*. Distances are in the user's unit; no date may be after today.
 */
final class ModificationForm
{
    public const int TITLE_MAX = 120;
    public const int FITTED_BY_MAX = 120;
    /** Room for a converted service record's whole title (150) and description (2,000), #431. */
    public const int NOTES_MAX = 2200;
    public const int REVERT_NOTE_MAX = 500;

    /**
     * @return array<string, string>
     */
    public static function defaults(DateTimeImmutable $today): array
    {
        return ['done_on' => $today->format('Y-m-d'), 'category' => ModificationCategory::Other->value];
    }

    /**
     * @return array<string, string>
     */
    public static function values(Modification $modification, DisplayPreferences $preferences): array
    {
        $data = $modification->data;

        return [
            'title' => $data->title,
            'category' => $data->category->value,
            'done_on' => $data->doneOn->format('Y-m-d'),
            'odometer' => $data->odometerKm === null
                ? ''
                : OdometerReadingForm::distanceForDisplay($data->odometerKm, $preferences),
            'cost' => Decimal::trim($data->cost),
            'fitted_by' => $data->fittedBy ?? '',
            'notes' => $data->notes ?? '',
        ];
    }

    /**
     * The modification's own fields, read from $validator, or null with errors left on it.
     */
    public static function read(
        Validator $validator,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        ?Modification $stored = null,
    ): ?ModificationData {
        $title = $validator->string('title', true, self::TITLE_MAX);
        $category = $validator->enum('category', ModificationCategory::class);
        $doneOn = $validator->date('done_on', true);
        $odometer = PartForm::odometer($validator, 'odometer');
        $cost = PartForm::money($validator, 'cost');
        $fittedBy = $validator->string('fitted_by', false, self::FITTED_BY_MAX);
        $notes = $validator->string('notes', false, self::NOTES_MAX);
        if ($doneOn !== null && $doneOn > $today) {
            $validator->addError('done_on', 'modification.error.future');
        }
        // *Declared on* is not on the page's form (it is *Mark declared*, #444): the API may send it,
        // and an edit that leaves it out keeps the stored date.
        $declaredOn = $stored?->data->declaredOn;
        if ($validator->has('declared_on')) {
            $declaredOn = $validator->date('declared_on');
            if ($declaredOn !== null && $declaredOn > $today) {
                $validator->addError('declared_on', 'modification.error.future');
            }
            if ($declaredOn !== null && $doneOn !== null && $declaredOn < $doneOn) {
                $validator->addError('declared_on', 'modification.error.declared_before');
            }
        }
        if ($title === null || $doneOn === null || !$validator->errors()->isEmpty()) {
            return null;
        }

        return new ModificationData(
            title: $title,
            category: $category ?? ModificationCategory::Other,
            doneOn: $doneOn,
            odometerKm: $odometer === null
                ? null
                : OdometerReadingForm::distanceToKm($odometer, $preferences, $stored?->data->odometerKm),
            cost: $cost ?? Decimal::round('0', PartForm::MONEY_SCALE),
            fittedBy: $fittedBy,
            notes: $notes,
            declaredOn: $declaredOn,
        );
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        ?Modification $stored = null,
    ): ModificationData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);

        return self::read($validator, $preferences, $today, $stored) ?? $validator->errors();
    }

    /**
     * *Revert*: a date (not before the modification, not after today), a
     * mileage, a note, and the parts to take off and put back.
     *
     * @param array<array-key, mixed> $input
     * @param list<int> $removable parts it fitted that are still on
     * @param list<int> $refittable stored parts it removed
     * @return array{
     *     on: DateTimeImmutable,
     *     km: ?string,
     *     note: ?string,
     *     remove: array<int, PartRemoval>,
     *     refit: list<int>,
     * }|ValidationErrors
     */
    public static function parseRevert(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        Modification $modification,
        array $removable,
        array $refittable,
    ): array|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        $on = $validator->date('reverted_on', true);
        $km = PartForm::odometer($validator, 'odometer');
        $note = $validator->string('note', false, self::REVERT_NOTE_MAX);
        if ($on !== null && $on > $today) {
            $validator->addError('reverted_on', 'modification.error.future');
        }
        if ($on !== null && $on < $modification->data->doneOn) {
            $validator->addError('reverted_on', 'modification.error.reverted_before');
        }
        $remove = [];
        foreach ($removable as $id) {
            if ($validator->checkbox('remove_' . $id)) {
                $removal = PartForm::removal($validator, 'remove_' . $id . '_');
                if ($removal !== null) {
                    $remove[$id] = $removal;
                }
            }
        }
        $refit = array_values(array_filter(
            $refittable,
            static fn (int $id): bool => $validator->checkbox('refit_' . $id),
        ));
        if ($on === null || !$validator->errors()->isEmpty()) {
            return $validator->errors();
        }

        return [
            'on' => $on,
            'km' => $km === null ? null : OdometerReadingForm::distanceToKm($km, $preferences),
            'note' => $note,
            'remove' => $remove,
            'refit' => $refit,
        ];
    }
}
