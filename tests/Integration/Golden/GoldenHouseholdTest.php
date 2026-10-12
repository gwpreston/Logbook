<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Golden;

use Brick\Math\BigDecimal;
use Logbook\Support\Money\Currency;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Golden\ExpectedSkeleton;
use Logbook\Tests\Support\Golden\GoldenFigures;
use Logbook\Tests\Support\Golden\GoldenHousehold;
use Logbook\Tests\Support\Golden\InputBuilder;
use Throwable;

/**
 * The golden household's money, checked to the penny (Phase 45.1): the
 * owner's figures in tests/Fixtures/golden/expected.csv against what the
 * app's own services give for the same households, as each row's viewer.
 *
 * Every mismatch is reported at once. A row whose `expected` is empty never
 * counts as a pass: while any is empty the test ends incomplete, so a
 * skeleton cannot give a green.
 *
 * Set GOLDEN_APP_FIGURES to a file name to have the run write the app's
 * figure for every row there (id,actual): tests/Fixtures/golden/app-figures.php
 * does, for the owner to compare with the spreadsheet. expected.csv is
 * never written.
 */
final class GoldenHouseholdTest extends AppTestCase
{
    private const string EXPECTED = __DIR__ . '/../../Fixtures/golden/expected.csv';
    private const string SCRIPT = __DIR__ . '/../../Fixtures/golden/independent/script.csv';

    /** @var array<string, string>|null the app's figure by row id, read once for the whole class */
    private static ?array $actuals = null;

    public function testEveryExpectedFigureMatchesTheApp(): void
    {
        $rows = ExpectedSkeleton::read(self::EXPECTED);
        $actuals = $this->actuals($rows);

        $mismatches = [];
        $empty = 0;
        foreach ($rows as $row) {
            if (trim($row['expected']) === '') {
                $empty++;

                continue;
            }
            $actual = $actuals[$row['id']];
            if (!self::same($row['expected'], $actual)) {
                $mismatches[] = self::describe($row, $row['expected'], $actual);
            }
        }

        self::assertSame(
            [],
            $mismatches,
            count($mismatches) . " of " . (count($rows) - $empty) . " filled figures differ from the app:\n"
            . implode("\n", $mismatches),
        );
        if ($empty > 0) {
            self::markTestIncomplete($empty . ' of ' . count($rows) . ' expected figures are not filled in yet');
        }
    }

    /**
     * Finance and depreciation are also worked out by an independent script
     * that shares no code with src/ (#393, tests/Fixtures/golden/independent/):
     * the app must agree with it on every row it fills, to the penny.
     */
    public function testTheIndependentScriptAgreesWithTheApp(): void
    {
        $rows = ExpectedSkeleton::read(self::EXPECTED);
        $actuals = $this->actuals($rows);
        $byId = array_column($rows, null, 'id');

        $script = ExpectedSkeleton::read(self::SCRIPT);
        self::assertSame(
            array_column(array_filter($rows, static fn (array $r): bool => $r['source'] === 'spreadsheet+script'), 'id'),
            array_column($script, 'id'),
            'the script fills every spreadsheet+script row, by the same ids',
        );
        $mismatches = [];
        foreach ($script as $line) {
            $row = $byId[$line['id']];
            self::assertSame($row['figure'], $line['figure'], "row {$line['id']} is the same figure");
            if (!self::same($line['script'], $actuals[$line['id']])) {
                $mismatches[] = self::describe($row, $line['script'], $actuals[$line['id']]);
            }
        }

        self::assertSame([], $mismatches, "The app differs from the independent script:\n" . implode("\n", $mismatches));
    }

    public function testEveryFigureCanBeRead(): void
    {
        $rows = ExpectedSkeleton::read(self::EXPECTED);
        $actuals = $this->actuals($rows);

        $malformed = [];
        foreach ($rows as $row) {
            $actual = $actuals[$row['id']];
            if (!self::wellFormed($row, $actual)) {
                $malformed[] = self::describe($row, '(a ' . $row['unit'] . ' figure)', $actual);
            }
        }

        self::assertSame([], $malformed, "Figures not well formed for their unit:\n" . implode("\n", $malformed));
    }

    /**
     * Load the household once and read every row, whichever test runs first.
     *
     * @param list<array<string, string>> $rows
     * @return array<string, string>
     */
    private function actuals(array $rows): array
    {
        if (self::$actuals !== null) {
            return self::$actuals;
        }
        $app = $this->createApp();
        $this->pinClock($app, InputBuilder::TODAY_UTC);
        $this->resetDatabase($app);
        $container = $app->getContainer();
        $figures = new GoldenFigures($container, GoldenHousehold::load($container, self::PASSWORD));

        $actuals = [];
        foreach ($rows as $row) {
            try {
                $actuals[$row['id']] = $figures->actual($row);
            } catch (Throwable $e) {
                self::fail("Row {$row['id']} ({$row['figure']}, {$row['viewer']}, {$row['vehicle']}, {$row['period']}) "
                    . 'cannot be read: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            }
        }
        $dump = getenv('GOLDEN_APP_FIGURES');
        if (is_string($dump) && $dump !== '') {
            $handle = fopen($dump, 'wb');
            self::assertNotFalse($handle, "Cannot write {$dump}");
            fputcsv($handle, ['id', 'actual'], ',', '"', '');
            foreach ($actuals as $id => $actual) {
                fputcsv($handle, [(string) $id, $actual], ',', '"', '');
            }
            fclose($handle);
        }

        return self::$actuals = $actuals;
    }

    /**
     * @param array<string, string> $row
     */
    private static function describe(array $row, string $expected, string $actual): string
    {
        return sprintf(
            '#%s %s viewer=%s vehicle=%s period=%s: expected %s, app gives %s',
            $row['id'],
            $row['figure'],
            $row['viewer'],
            $row['vehicle'],
            $row['period'],
            $expected,
            $actual,
        );
    }

    /**
     * Equal as numbers when both are (so 12.5 and 12.50, or 1,234.50 and 1234.50, agree), else as text.
     * A leading "at_least" must match too.
     */
    private static function same(string $expected, string $actual): bool
    {
        $e = self::normal($expected);
        $a = self::normal($actual);
        if ($e === $a) {
            return true;
        }
        [$eLeast, $eNumber] = self::split($e);
        [$aLeast, $aNumber] = self::split($a);

        return $eLeast === $aLeast
            && is_numeric($eNumber)
            && is_numeric($aNumber)
            && BigDecimal::of($eNumber)->compareTo(BigDecimal::of($aNumber)) === 0;
    }

    private static function normal(string $value): string
    {
        $value = strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
        $value = str_replace(',', '', $value);

        return str_replace('−', '-', $value);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private static function split(string $value): array
    {
        return str_starts_with($value, 'at_least ') ? [true, substr($value, 9)] : [false, $value];
    }

    /**
     * @param array<string, string> $row
     */
    private static function wellFormed(array $row, string $actual): bool
    {
        if ($actual === GoldenFigures::NONE) {
            return true;
        }
        $places = $row['currency'] === '' ? 2 : Currency::fractionDigits($row['currency']);
        $number = static fn (int $decimals): string => $decimals === 0 ? '-?\d+' : '-?\d+\.\d{' . $decimals . '}';
        $pattern = match ($row['unit']) {
            'money', 'money/month' => $number($places),
            'money/mi', 'money/km' => $number($places + 1),
            'mi', 'km', 'count' => $number(0),
            'l/100km', 'kWh/100km' => $number(2),
            default => null,
        };
        if ($row['figure'] === 'hidden.golf_costs') {
            return $actual === 'none';
        }
        if ($pattern === null) {
            return false;
        }
        $prefix = $row['figure'] === 'coming_up.total' ? '(at_least )?' : '';

        return preg_match('/^' . $prefix . $pattern . '$/', $actual) === 1;
    }
}
