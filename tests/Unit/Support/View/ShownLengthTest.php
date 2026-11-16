<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\View;

use Logbook\Support\View\TwigExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

/**
 * The stat tile's long-figure rule counts what the tile shows (spec.md §8
 * *Figures keep their units*, K208, #627): markup from a macro, such as
 * Coming up's "—" with words for screen readers, is as short as it looks.
 */
final class ShownLengthTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function values(): iterable
    {
        yield 'a figure and its unit, the no-break space as one' => ["0,301\u{00A0}€/kWh", 11];
        yield 'nine characters' => ['£0.055/km', 9];
        yield 'a dash with words for screen readers' => [
            new Markup('<span aria-hidden="true">—</span><span class="visually-hidden">No known cost</span>', 'UTF-8'),
            1,
        ];
        yield 'an entity counts as what it shows' => [new Markup('about &pound;656.00', 'UTF-8'), 13];
        yield 'spaces around markup are not shown' => ["  42\n", 2];
        yield 'a number' => [1234, 4];
        yield 'nothing' => [null, 0];
    }

    #[DataProvider('values')]
    public function testItCountsOnlyWhatTheTileShows(mixed $value, int $length): void
    {
        self::assertSame($length, TwigExtension::shownLength($value));
    }
}
