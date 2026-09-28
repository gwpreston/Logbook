<?php

declare(strict_types=1);

namespace Logbook\Service\Expense;

use DateTimeImmutable;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit expense form ↔ ExpenseEntryData. The amount may be blank or 0
 * (spec.md §8: a zero cost is valid).
 */
final class ExpenseEntryForm
{
    public const int NOTE_MAX = 500;
    /** decimal(14,3) money column. */
    public const int MONEY_WHOLE_DIGITS = 11;
    public const int MONEY_SCALE = 3;

    /**
     * @return array<string, string>
     */
    public static function values(ExpenseEntry $entry): array
    {
        $data = $entry->data;

        return [
            'spent_on' => $data->spentOn->format('Y-m-d'),
            'category' => $data->category->value,
            'amount' => Decimal::trim($data->amount),
            'note' => $data->note ?? '',
        ];
    }

    /**
     * @param DateTimeImmutable $today calendar date
     * @return array<string, string>
     */
    public static function defaults(DateTimeImmutable $today): array
    {
        return [
            'spent_on' => $today->format('Y-m-d'),
            'category' => ExpenseCategory::Parking->value,
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, DisplayPreferences $preferences): ExpenseEntryData|ValidationErrors
    {
        $validator = new Validator($input, $preferences->locale);

        $spentOn = $validator->date('spent_on', true);
        $category = $validator->enum('category', ExpenseCategory::class, true);
        $amount = $validator->decimal('amount', false, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
        $note = $validator->string('note', false, self::NOTE_MAX);

        if (!$validator->errors()->isEmpty() || $spentOn === null || $category === null) {
            return $validator->errors();
        }

        return new ExpenseEntryData(
            spentOn: $spentOn,
            category: $category,
            amount: $amount ?? Decimal::round('0', self::MONEY_SCALE),
            note: $note,
        );
    }
}
