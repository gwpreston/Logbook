<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\View;

use Logbook\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * On a phone every compact control has a hit area of at least 44 × 44 px and
 * keeps how it looks (spec.md §8 *Tap targets*, #457; K16, K35, K55): one
 * narrow-width block gives each a centred `::after`, and nothing in it
 * changes the drawn control. A *Coming up* row's cost shrinks and wraps
 * so its title keeps its room (§7.18 *Narrow rows*, K41).
 */
final class TapTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function controls(): iterable
    {
        foreach (
            [
                '.btn--soft', '.segmented__option', '.back-link', '.card__link', 'a.breakdown__label', 'summary',
                '.part-timeline__record', '.insurer-row__title a', '.insurer-row .btn--ghost',
            ] as $selector
        ) {
            yield $selector => [$selector];
        }
    }

    #[DataProvider('controls')]
    public function testTheControlGetsA44PxHitAreaOnAPhone(string $selector): void
    {
        $block = self::phoneBlock();
        $quoted = preg_quote($selector, '/');
        self::assertMatchesRegularExpression('/(^|,)\s*' . $quoted . '\s*(,[^{]*)?\{\s*position:\s*relative;\s*\}/m', $block);
        self::assertSame(1, preg_match('/(^|,)\s*' . $quoted . '::after\s*(,[^{]*)?\{([^}]*)\}/m', $block, $m), $selector . '::after');
        $rule = $m[3];
        self::assertMatchesRegularExpression('/content:\s*"";/', $rule);
        self::assertMatchesRegularExpression('/position:\s*absolute;/', $rule);
        self::assertMatchesRegularExpression('/width:\s*max\(100%,\s*2\.75rem\);/', $rule);
        self::assertMatchesRegularExpression('/height:\s*max\(100%,\s*2\.75rem\);/', $rule);
        self::assertMatchesRegularExpression('/transform:\s*translate\(-50%,\s*-50%\);/', $rule);
    }

    public function testTheBlockDrawsNothingDifferently(): void
    {
        // Only the hit area: no size, spacing, colour or font of the control itself.
        $block = self::phoneBlock();
        foreach (['min-height', 'padding', 'margin', 'font', 'color', 'background', 'border'] as $property) {
            self::assertDoesNotMatchRegularExpression('/(^|[\s;{])' . $property . '[a-z-]*\s*:/', $block, $property);
        }
    }

    public function testThePartsPagesSmallLinksCarryTheClass(): void
    {
        $part = (string) file_get_contents(Kernel::rootDir() . '/templates/parts/part_show.twig');
        self::assertSame(2, substr_count($part, '<a class="part-timeline__record"'), 'both of the fitting row\'s record links');
        $insurer = (string) file_get_contents(Kernel::rootDir() . '/templates/parts/insurer.twig');
        self::assertStringContainsString('<h3 class="insurer-row__title"><a ', $insurer);
        self::assertStringContainsString('class="btn btn--soft" name="only"', $insurer, '*Mark declared* is a soft button');
    }

    public function testAComingUpRowsCostShrinksAndWraps(): void
    {
        $css = self::css();
        self::assertSame(1, preg_match('/\n\.list__end\.forecast-item__end \{([^}]*)\}/', $css, $m), 'the cost column rule');
        self::assertMatchesRegularExpression('/flex:\s*0 1 auto;/', $m[1]);
        self::assertMatchesRegularExpression('/max-width:\s*40%;/', $m[1]);
        self::assertSame(1, preg_match('/\n\.forecast-item__end \.as-of \{([^}]*)\}/', $css, $m), 'the as-of rule');
        self::assertMatchesRegularExpression('/white-space:\s*normal;/', $m[1]);
        // Log rows keep their amount on one line.
        self::assertSame(1, preg_match('/\n\.list__end \{([^}]*)\}/', $css, $m));
        self::assertMatchesRegularExpression('/flex:\s*none;/', $m[1]);

        $forecast = (string) file_get_contents(Kernel::rootDir() . '/templates/macros/forecast.twig');
        self::assertStringContainsString('<span class="list__end forecast-item__end">', $forecast);
    }

    private static function css(): string
    {
        return (string) file_get_contents(Kernel::rootDir() . '/assets/css/app.css');
    }

    private static function phoneBlock(): string
    {
        $css = self::css();
        $start = strpos($css, "@media (max-width: 47.99rem) {\n    .btn--soft,");
        self::assertNotFalse($start, 'the tap-target block');
        $end = strpos($css, "\n}\n", $start);
        self::assertNotFalse($end);

        return substr($css, $start, $end - $start);
    }
}
