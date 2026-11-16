<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\View;

use Logbook\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * A chart's screen-reader table stays in full but costs little to lay out
 * (spec.md §8 *Printing reports*, K204, #624): with JS on the browser skips
 * laying it out while it is off screen, and a printed report still lays it
 * out and prints it.
 */
final class ChartTableLayoutTest extends TestCase
{
    public function testTheClippedTableIsLaidOutOnlyNearTheScreen(): void
    {
        $rule = self::rule(self::css(), '.js .chart-table');

        self::assertMatchesRegularExpression('/content-visibility:\s*auto;/', $rule);
        self::assertMatchesRegularExpression('/contain-intrinsic-size:\s*1px 1px;/', $rule);
    }

    public function testAPrintedReportStillLaysTheTableOut(): void
    {
        $css = self::css();
        $print = substr($css, (int) strpos($css, '@media print'));
        $rule = self::rule($print, '.js .print-report .chart-table');

        self::assertMatchesRegularExpression('/content-visibility:\s*visible;/', $rule);
    }

    /** The declarations of the first rule whose selector is exactly $selector. */
    private static function rule(string $css, string $selector): string
    {
        $found = preg_match('/^\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $css, $m);
        self::assertSame(1, $found, $selector . ' rule');

        return $m[1];
    }

    private static function css(): string
    {
        return (string) file_get_contents(Kernel::rootDir() . '/assets/css/app.css');
    }
}
