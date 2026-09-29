<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Units;

use Logbook\Support\Units\DepthUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tread depth in mm or 32nds of an inch (spec.md §8, §7.17): stored in
 * millimetres with three places, so a value typed in 32nds round-trips.
 */
final class DepthUnitTest extends TestCase
{
    public function testTenThirtySecondsRoundTrips(): void
    {
        $unit = DepthUnit::ThirtySecond;
        $mm = $unit->toMm('10');

        self::assertSame('7.938', $mm);
        self::assertSame('10', $unit->toInput($mm));
        self::assertSame('10', $unit->formatNumber($mm, 'en_GB'));
    }

    public function testHalvesOfAThirtySecond(): void
    {
        $unit = DepthUnit::ThirtySecond;
        $mm = $unit->toMm('6.5');

        self::assertSame('5.159', $mm);
        self::assertSame('6.5', $unit->toInput($mm));
        self::assertSame('6½', $unit->formatNumber($mm, 'de_DE'), '32nds are locale-neutral');
        self::assertSame('tyre.error.depth_halves', $unit->problem('6.3'));
        self::assertNull($unit->problem('6.5'));
    }

    public function testEveryHalfThirtySecondSurvivesStorage(): void
    {
        $unit = DepthUnit::ThirtySecond;
        for ($halves = 0; $halves <= 50; $halves++) {
            $typed = rtrim(rtrim(sprintf('%.1f', $halves / 2), '0'), '.');
            self::assertSame($typed, $unit->toInput($unit->toMm($typed)), $typed . '/32');
        }
    }

    public function testMillimetresShowOneDecimalInTheLocale(): void
    {
        $unit = DepthUnit::Millimetre;

        self::assertSame('4.2', $unit->formatNumber('4.237', 'en_GB'));
        self::assertSame('4,2', $unit->formatNumber('4.237', 'de_DE'));
        self::assertSame('4.24', $unit->toInput('4.237'));
        self::assertSame('8', $unit->toInput('8.000'));
    }

    /**
     * @return iterable<string, array{DepthUnit, string, ?string}>
     */
    public static function limits(): iterable
    {
        yield '0 mm is valid' => [DepthUnit::Millimetre, '0', null];
        yield '20 mm is valid' => [DepthUnit::Millimetre, '20', null];
        yield '21 mm is not' => [DepthUnit::Millimetre, '21', 'tyre.error.depth_range'];
        yield '-1 mm is not' => [DepthUnit::Millimetre, '-1', 'tyre.error.depth_range'];
        yield '0/32 is valid' => [DepthUnit::ThirtySecond, '0', null];
        yield '25/32 is valid' => [DepthUnit::ThirtySecond, '25', null];
        yield '25.5/32 is not' => [DepthUnit::ThirtySecond, '25.5', 'tyre.error.depth_range'];
    }

    #[DataProvider('limits')]
    public function testLimits(DepthUnit $unit, string $value, ?string $problem): void
    {
        self::assertSame($problem, $unit->problem($value));
    }
}
