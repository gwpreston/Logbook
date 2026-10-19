<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Service\Ai\Scan\PartLines;
use PHPUnit\Framework\TestCase;

/**
 * A service invoice's parts lines as part rows (spec.md §7.27 *Parts
 * lines*, #440): a guess for the owner to check.
 */
final class PartLinesTest extends TestCase
{
    public function testANameAndATrailingPrice(): void
    {
        $rows = PartLines::parse(['Oil filter 12.50', 'Air filter £23.00'], 'en_GB');

        self::assertSame('Oil filter', $rows[0]['name']);
        self::assertSame('12.5', $rows[0]['price']);
        self::assertNull($rows[0]['part_number']);
        self::assertSame('Air filter', $rows[1]['name']);
        self::assertSame('23', $rows[1]['price']);
        self::assertSame('Oil filter 12.50', $rows[0]['line'], 'the line as printed, for its evidence');
    }

    public function testAPartNumberIsTakenOutOfTheName(): void
    {
        $rows = PartLines::parse(['Oil filter OC593 12.50', '2 x Brake pads (1K0-698-151) £61.20', 'Wiper blade'], 'en_GB');

        self::assertSame(['Oil filter', 'OC593', '12.5'], [$rows[0]['name'], $rows[0]['part_number'], $rows[0]['price']]);
        $pads = [$rows[1]['name'], $rows[1]['part_number'], $rows[1]['price']];
        self::assertSame(['Brake pads ×2', '1K0-698-151', '61.2'], $pads);
        self::assertSame(['Wiper blade', null, null], [$rows[2]['name'], $rows[2]['part_number'], $rows[2]['price']]);
    }

    public function testGradesAndSizesAreNotPartNumbers(): void
    {
        $rows = PartLines::parse(['Engine oil 5W-30 4.5 litres 38.25', 'Tyre 205/55R16 91V 80.00'], 'en_GB');

        self::assertNull($rows[0]['part_number']);
        self::assertSame('Engine oil 5W-30 4.5 litres', $rows[0]['name']);
        self::assertSame('38.25', $rows[0]['price']);
        self::assertNull($rows[1]['part_number'], 'a tyre size is not a part number');
    }

    public function testPricesReadInTheUsersLocaleAndOddLinesSurvive(): void
    {
        $de = PartLines::parse(['Ölfilter 1.234,50 €'], 'de_DE');
        self::assertSame('1234.5', $de[0]['price']);
        self::assertSame('Ölfilter', $de[0]['name']);

        $odd = PartLines::parse(['', '   ', '12.50', 'Gasket set'], 'en_GB');
        self::assertCount(2, $odd, 'empty lines are skipped');
        self::assertSame('12.50', $odd[0]['name'], 'a line that is only a price keeps its text');
        self::assertSame('Gasket set', $odd[1]['name']);
    }

    public function testSpaceGroupedPricesQuantitiesAndSuffixes(): void
    {
        // K18.
        $rows = PartLines::parse([
            'Turbocharger 1 234,56 €',
            '2 x Wiper blade 24.00',
            'Engine oil 5W-30 £42.00 x 5',
            'Pollen filter £18.00 (VAT 20%)',
            'Bulb H7 £4.99 Z',
            'Coolant 9.95 Qty 2',
        ], 'de_DE');

        self::assertSame(['Turbocharger', '1234.56'], [$rows[0]['name'], $rows[0]['price']]);
        self::assertSame(['Wiper blade ×2', '24'], [$rows[1]['name'], $rows[1]['price']]);
        self::assertSame(['Engine oil 5W-30 ×5', '42'], [$rows[2]['name'], $rows[2]['price']]);
        self::assertSame(['Pollen filter', '18'], [$rows[3]['name'], $rows[3]['price']]);
        self::assertSame('4.99', $rows[4]['price']);
        self::assertSame(['Coolant ×2', '9.95'], [$rows[5]['name'], $rows[5]['price']]);
    }

    public function testASizeOrALetterAfterANumberStaysWithoutAPrice(): void
    {
        // K96, K97: a quantity or VAT code is read only after a price.
        $lines = ['Tyre 205 x 55', 'Spacer 20 x 5', 'Fuse 10 A', 'Bulb 12 V', 'Fuse 10 A 2.50', 'Tyre 205 x 55 89.99 x2'];
        $rows = PartLines::parse($lines, 'en_GB');

        self::assertSame(['Tyre 205 x 55', null], [$rows[0]['name'], $rows[0]['price']]);
        self::assertSame('Spacer 20 x 5', $rows[1]['name']);
        self::assertSame('Fuse 10 A', $rows[2]['name']);
        self::assertSame('Bulb 12 V', $rows[3]['name']);
        self::assertSame(['Fuse 10 A', '2.5'], [$rows[4]['name'], $rows[4]['price']]);
        self::assertSame(['Tyre 205 x 55 ×2', '89.99'], [$rows[5]['name'], $rows[5]['price']]);
    }

    public function testAtMostTwentyLinesAndNamesAreCut(): void
    {
        $many = array_map(static fn (int $i): string => 'Part ' . $i, range(1, 30));
        self::assertCount(PartLines::MAX, PartLines::parse($many, 'en_GB'));

        $long = PartLines::parse([str_repeat('x', 300)], 'en_GB');
        self::assertSame(120, mb_strlen($long[0]['name']));
    }
}
