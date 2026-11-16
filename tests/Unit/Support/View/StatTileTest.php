<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\View;

use Logbook\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * A long figure shrinks with a narrow stat tile instead of breaking its
 * unit, and never below the body size (spec.md §8 *Figures keep their
 * units*, K208, #627). Which figures count as long is pinned by
 * FuelTest::testALongStatFigureIsMarkedToShrinkWithItsTile.
 */
final class StatTileTest extends TestCase
{
    public function testTheTileIsTheContainerItsFigureSizesTo(): void
    {
        self::assertMatchesRegularExpression('/container-type:\s*inline-size;/', self::rule('.stat'));
    }

    public function testALongFigureShrinksNoSmallerThanTheBodySize(): void
    {
        self::assertMatchesRegularExpression(
            '/font-size:\s*clamp\(var\(--fs-md\),\s*\d+(\.\d+)?cqi,\s*var\(--fs-figure\)\);/',
            self::rule('.stat__value--long'),
        );
    }

    /** The declarations of the rule whose selector is exactly $selector. */
    private static function rule(string $selector): string
    {
        $css = (string) file_get_contents(Kernel::rootDir() . '/assets/css/app.css');
        $found = preg_match('/^' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $css, $m);
        self::assertSame(1, $found, $selector . ' rule');

        return $m[1];
    }
}
