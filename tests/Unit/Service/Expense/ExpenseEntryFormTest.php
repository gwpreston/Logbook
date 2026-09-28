<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Expense;

use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Service\Expense\ExpenseEntryForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

final class ExpenseEntryFormTest extends TestCase
{
    private const array VALID = [
        'spent_on' => '2026-09-14',
        'category' => 'tolls',
        'amount' => '2.5',
        'note' => 'Dartford Crossing',
    ];

    public function testParsesAValidExpense(): void
    {
        $data = ExpenseEntryForm::parse(self::VALID, self::prefs('en_GB'));

        self::assertInstanceOf(ExpenseEntryData::class, $data);
        self::assertSame('2026-09-14', $data->spentOn->format('Y-m-d'));
        self::assertSame(ExpenseCategory::Tolls, $data->category);
        self::assertSame('2.500', $data->amount);
        self::assertSame('Dartford Crossing', $data->note);
    }

    public function testZeroAndBlankAmountsAreValid(): void
    {
        foreach (['0', '', '0.00'] as $amount) {
            $data = ExpenseEntryForm::parse(['amount' => $amount, 'note' => ''] + self::VALID, self::prefs('en_GB'));
            self::assertInstanceOf(ExpenseEntryData::class, $data, sprintf('amount "%s"', $amount));
            self::assertSame('0.000', $data->amount);
            self::assertNull($data->note);
        }
    }

    public function testRejectsWhatIsGenuinelyWrong(): void
    {
        $errors = ExpenseEntryForm::parse(
            ['spent_on' => '2026-02-30', 'category' => 'fuel', 'amount' => '-1', 'note' => str_repeat('x', 501)],
            self::prefs('en_GB'),
        );

        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertSame(['spent_on', 'category', 'amount', 'note'], array_keys($errors->all()));
    }

    public function testAcceptsTheOwnersNumberFormat(): void
    {
        $data = ExpenseEntryForm::parse(['amount' => '1.234,56'] + self::VALID, self::prefs('de_DE'));

        self::assertInstanceOf(ExpenseEntryData::class, $data);
        self::assertSame('1234.560', $data->amount);
    }

    private static function prefs(string $locale): DisplayPreferences
    {
        $preset = UnitPreset::Uk;

        return new DisplayPreferences(
            $locale,
            'Europe/London',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        );
    }
}
