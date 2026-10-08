<?php

declare(strict_types=1);

namespace Logbook\Service\Issue;

use DateTimeImmutable;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Issue\IssueUpdate;
use Logbook\Domain\Issue\IssueUpdateData;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The issue forms (spec.md §7.37): the issue itself, *Add update*, *Watch*
 * and *Fixed without a record*. Odometers are in the user's distance unit;
 * no date may be after the user's today.
 */
final class IssueForm
{
    public const int TITLE_MAX = 120;
    public const int DESCRIPTION_MAX = 2000;
    public const int NOTE_MAX = 1000;

    /**
     * A new issue: noticed today, open.
     *
     * @return array<string, string>
     */
    public static function defaults(DateTimeImmutable $today): array
    {
        return [
            'noticed_on' => $today->format('Y-m-d'),
            'status' => IssueStatus::Open->value,
        ];
    }

    /**
     * An existing issue as the edit form shows it.
     *
     * @return array<string, string>
     */
    public static function values(Issue $issue, DisplayPreferences $preferences): array
    {
        $data = $issue->data;

        return [
            'noticed_on' => $data->noticedOn->format('Y-m-d'),
            'odometer' => self::distance($data->odometerKm, $preferences),
            'title' => $data->title,
            'description' => $data->description ?? '',
            'category' => $data->category->value ?? '',
            'status' => $data->status->value,
            'affects_safety' => $data->affectsSafety ? '1' : '',
            'look_again_on' => $data->lookAgainOn?->format('Y-m-d') ?? '',
            'look_again_odometer' => self::distance($data->lookAgainKm, $preferences),
        ];
    }

    /**
     * An update's note as its edit form shows it.
     *
     * @return array<string, string>
     */
    public static function updateValues(IssueUpdate $update, DisplayPreferences $preferences): array
    {
        return [
            'noted_on' => $update->notedOn->format('Y-m-d'),
            'odometer' => self::distance($update->odometerKm, $preferences),
            'note' => $update->note ?? '',
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     * @param DateTimeImmutable $today calendar date in the user's time zone
     * @param Issue|null $stored the edited issue: its odometers are kept unless changed
     */
    public static function parse(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        ?Issue $stored = null,
    ): IssueData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);

        $noticedOn = $validator->date('noticed_on', true);
        $title = $validator->string('title', true, self::TITLE_MAX);
        $description = $validator->string('description', false, self::DESCRIPTION_MAX);
        $category = $validator->enum('category', MaintenanceCategory::class);
        $status = $validator->enum('status', IssueStatus::class);
        $odometer = self::odometer($validator, 'odometer');
        $safety = $validator->checkbox('affects_safety');
        $lookOn = $validator->date('look_again_on');
        $lookKm = self::odometer($validator, 'look_again_odometer');

        if ($noticedOn !== null && $noticedOn > $today) {
            $validator->addError('noticed_on', 'issue.error.future');
        }
        $watching = $status === IssueStatus::Watching;
        if (!$watching && ($lookOn !== null || $lookKm !== null)) {
            $validator->addError('look_again_on', 'issue.error.look_again_watching');
        }
        if ($lookOn !== null && $noticedOn !== null && $lookOn < $noticedOn) {
            $validator->addError('look_again_on', 'issue.error.look_again_before');
        }

        if (!$validator->errors()->isEmpty() || $noticedOn === null || $title === null) {
            return $validator->errors();
        }

        return new IssueData(
            noticedOn: $noticedOn,
            title: $title,
            status: $status ?? IssueStatus::Open,
            odometerKm: $odometer === null
                ? null
                : OdometerReadingForm::distanceToKm($odometer, $preferences, $stored?->data->odometerKm),
            description: $description,
            category: $category,
            affectsSafety: $safety,
            lookAgainOn: $watching ? $lookOn : null,
            lookAgainKm: $watching && $lookKm !== null
                ? OdometerReadingForm::distanceToKm($lookKm, $preferences, $stored?->data->lookAgainKm)
                : null,
        );
    }

    /**
     * *Add update* (and a note's edit): a date, a note, an odometer and,
     * on add, an optional status change to open or watching.
     *
     * @param array<array-key, mixed> $input
     * @param IssueUpdate|null $stored the edited note: its odometer is kept unless changed
     */
    public static function parseUpdate(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        Issue $issue,
        ?IssueUpdate $stored = null,
    ): IssueUpdateData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);

        $notedOn = $validator->date('noted_on', true);
        $note = $validator->string('note', false, self::NOTE_MAX);
        $odometer = self::odometer($validator, 'odometer');
        $choices = [IssueStatus::Open->value, IssueStatus::Watching->value];
        $status = $stored === null ? $validator->choice('status', $choices) : null;
        $lookOn = $validator->date('look_again_on');
        $lookKm = self::odometer($validator, 'look_again_odometer');
        $to = $status === null ? null : IssueStatus::from($status);
        $changes = $to !== null && $to !== $issue->status();

        if ($notedOn !== null && $notedOn > $today) {
            $validator->addError('noted_on', 'issue.error.future');
        }
        if ($notedOn !== null && $notedOn < $issue->data->noticedOn) {
            $validator->addError('noted_on', 'issue.error.before_noticed');
        }
        if ($changes && $issue->isFixed()) {
            $validator->addError('status', 'issue.error.fixed');
        }
        if ($note === null && !$changes) {
            $validator->addError('note', 'issue.error.note_required');
        }
        if ($to !== IssueStatus::Watching && ($lookOn !== null || $lookKm !== null)) {
            $validator->addError('look_again_on', 'issue.error.look_again_watching');
        }

        if (!$validator->errors()->isEmpty() || $notedOn === null) {
            return $validator->errors();
        }

        return new IssueUpdateData(
            notedOn: $notedOn,
            note: $note,
            odometerKm: $odometer === null
                ? null
                : OdometerReadingForm::distanceToKm($odometer, $preferences, $stored?->odometerKm),
            status: $changes ? $to : null,
            lookAgainOn: $to === IssueStatus::Watching ? $lookOn : null,
            lookAgainKm: $to === IssueStatus::Watching && $lookKm !== null
                ? OdometerReadingForm::distanceToKm($lookKm, $preferences)
                : null,
        );
    }

    /**
     * *Watch* and *Watch again*: an optional look-again date (not before
     * today) and/or odometer.
     *
     * @param array<array-key, mixed> $input
     */
    public static function parseWatch(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
    ): LookAgain|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        $lookOn = $validator->date('look_again_on');
        $lookKm = self::odometer($validator, 'look_again_odometer');
        if ($lookOn !== null && $lookOn < $today) {
            $validator->addError('look_again_on', 'issue.error.look_again_past');
        }
        if (!$validator->errors()->isEmpty()) {
            return $validator->errors();
        }

        return new LookAgain($lookOn, $lookKm === null ? null : OdometerReadingForm::distanceToKm($lookKm, $preferences));
    }

    /**
     * *Fixed without a record*: the date (not before it was noticed, not
     * after today) and an optional note.
     *
     * @param array<array-key, mixed> $input
     */
    public static function parseFixedWithout(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        Issue $issue,
    ): IssueUpdateData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        $fixedOn = $validator->date('fixed_on', true);
        $note = $validator->string('note', false, self::NOTE_MAX);
        if ($fixedOn !== null && $fixedOn > $today) {
            $validator->addError('fixed_on', 'issue.error.future');
        }
        if ($fixedOn !== null && $fixedOn < $issue->data->noticedOn) {
            $validator->addError('fixed_on', 'issue.error.before_noticed');
        }
        if (!$validator->errors()->isEmpty() || $fixedOn === null) {
            return $validator->errors();
        }

        return new IssueUpdateData($fixedOn, $note);
    }

    private static function odometer(Validator $validator, string $field): ?string
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

    private static function distance(?string $km, DisplayPreferences $preferences): string
    {
        return $km === null ? '' : OdometerReadingForm::distanceForDisplay($km, $preferences);
    }
}
