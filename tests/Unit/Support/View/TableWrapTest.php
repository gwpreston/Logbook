<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\View;

use Logbook\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * A wide table scrolls inside its `.table-wrap`, not the page (K52): the box is
 * positioned, so the absolutely positioned hidden text in its cells is laid out
 * inside the scroll box instead of stretching the page at 375 px.
 */
final class TableWrapTest extends TestCase
{
    public function testTheScrollBoxHoldsItsHiddenText(): void
    {
        $css = (string) file_get_contents(Kernel::rootDir() . '/assets/css/app.css');
        self::assertSame(1, preg_match('/\n\.table-wrap \{([^}]*)\}/', $css, $m), 'the .table-wrap rule');
        self::assertMatchesRegularExpression('/\bposition:\s*relative;/', $m[1] ?? '');
        self::assertMatchesRegularExpression('/\boverflow-x:\s*auto;/', $m[1] ?? '');
    }

    public function testTheStationTablesSitInTheScrollBox(): void
    {
        foreach (['near', 'show'] as $page) {
            $twig = (string) file_get_contents(Kernel::rootDir() . '/templates/stations/' . $page . '.twig');
            self::assertSame(
                substr_count($twig, '<table'),
                substr_count($twig, 'class="table-wrap"'),
                $page . ': every table in a .table-wrap',
            );
        }
    }
}
