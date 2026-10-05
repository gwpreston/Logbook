<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\View;

use Logbook\Kernel;
use Logbook\Support\Display\Accent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text colours in assets/css/app.css meet WCAG 2.2 AA contrast (4.5:1 for
 * text) against the backgrounds they are used on, in both themes, and the
 * two copies of the dark theme stay identical.
 */
final class ColourContrastTest extends TestCase
{
    private const float AA_TEXT = 4.5;

    /** Foreground token, background token(s) layered bottom-up. */
    private const array PAIRS = [
        ['text', ['bg']],
        ['text', ['surface']],
        ['text', ['surface-2']],
        ['muted', ['bg']],
        ['muted', ['surface']],
        ['muted', ['surface-2']],
        ['accent', ['surface']],
        ['accent', ['bg']],
        ['accent', ['surface', 'accent-soft']],
        ['on-accent', ['accent']],
        ['green', ['surface']],
        ['green', ['surface', 'green-soft']],
        ['amber', ['surface']],
        ['amber', ['surface', 'amber-soft']],
        ['red', ['surface']],
        ['red', ['surface', 'red-soft']],
        ['on-plate', ['plate']],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function themes(): iterable
    {
        yield 'light' => ['light'];
        yield 'dark' => ['dark'];
    }

    #[DataProvider('themes')]
    public function testTextContrast(string $theme): void
    {
        $tokens = self::tokens($theme);
        $failures = [];
        foreach (self::PAIRS as [$foreground, $layers]) {
            $background = [1.0, 1.0, 1.0];
            foreach ($layers as $layer) {
                $background = self::over(self::colour($tokens[$layer]), $background);
            }
            $ratio = self::contrast(self::over(self::colour($tokens[$foreground]), $background), $background);
            if ($ratio < self::AA_TEXT) {
                $failures[] = sprintf('%s on %s: %.2f:1', $foreground, implode('+', $layers), $ratio);
            }
        }

        self::assertSame([], $failures);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accents(): iterable
    {
        foreach (Accent::cases() as $accent) {
            yield $accent->value . ' light' => [$accent->value, 'light'];
            yield $accent->value . ' dark' => [$accent->value, 'dark'];
        }
    }

    /**
     * Every accent keeps button text, links and the focus ring readable,
     * and never touches the status colours or the plate.
     */
    #[DataProvider('accents')]
    public function testAccentContrast(string $accent, string $theme): void
    {
        $tokens = self::accentTokens($accent, $theme) + self::tokens($theme);
        $failures = [];
        foreach (self::PAIRS as [$foreground, $layers]) {
            if (!str_contains($foreground . implode(' ', $layers), 'accent')) {
                continue;
            }
            $background = [1.0, 1.0, 1.0];
            foreach ($layers as $layer) {
                $background = self::over(self::colour($tokens[$layer]), $background);
            }
            $ratio = self::contrast(self::over(self::colour($tokens[$foreground]), $background), $background);
            if ($ratio < self::AA_TEXT) {
                $failures[] = sprintf('%s on %s: %.2f:1', $foreground, implode('+', $layers), $ratio);
            }
        }
        // The focus ring (the accent itself) against the page: 3:1 for non-text.
        foreach (['bg', 'surface'] as $page) {
            $white = [1.0, 1.0, 1.0];
            $ratio = self::contrast(
                self::over(self::colour($tokens['accent']), $white),
                self::over(self::colour($tokens[$page]), $white),
            );
            if ($ratio < 3.0) {
                $failures[] = sprintf('focus ring on %s: %.2f:1', $page, $ratio);
            }
        }

        self::assertSame([], $failures);
        if ($accent !== Accent::DEFAULT->value) {
            $changed = array_keys(self::accentTokens($accent, $theme));
            // A violet accent also moves documents off violet, so Fuel (the accent) stays apart in cost bars (Phase 33.4).
            $violet = in_array($accent, ['indigo', 'purple'], true);
            $expected = $violet ? ['accent', 'c-ins', 'accent-soft'] : ['accent', 'accent-soft'];
            self::assertSame($expected, $changed, 'only accent tokens change');
        }
    }

    public function testBothDarkAccentBlocksMatch(): void
    {
        $css = self::css();
        foreach (Accent::cases() as $accent) {
            if ($accent === Accent::DEFAULT) {
                continue;
            }
            $pattern = '/:root\[data-accent="' . $accent->value . '"\]:not\(\[data-theme="light"\]\) \{(.*?)\}/s';
            $media = preg_match($pattern, $css, $m) === 1 ? $m[1] : '';
            $explicit = self::accentTokens($accent->value, 'dark');
            self::assertNotSame([], $explicit, $accent->value);
            self::assertSame(self::declarations($media), $explicit, $accent->value);
        }
    }

    /**
     * @return array<string, string> the tokens an accent overrides in a theme
     */
    private static function accentTokens(string $accent, string $theme): array
    {
        $pattern = $theme === 'light'
            ? '/:root\[data-accent="' . $accent . '"\] \{(.*?)\}/s'
            : '/:root\[data-accent="' . $accent . '"\]\[data-theme="dark"\] \{(.*?)\}/s';

        return preg_match($pattern, self::css(), $m) === 1 ? self::declarations($m[1]) : [];
    }

    public function testBothDarkThemeBlocksMatch(): void
    {
        $css = self::css();
        preg_match('/@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme="light"\]\) \{(.*?)\}/s', $css, $media);
        preg_match('/:root\[data-theme="dark"\] \{(.*?)\}/s', $css, $explicit);

        self::assertNotEmpty($media[1] ?? null);
        self::assertSame(self::declarations($media[1] ?? ''), self::declarations($explicit[1] ?? ''));
    }

    /**
     * @return array<string, string> token → value
     */
    private static function tokens(string $theme): array
    {
        $css = self::css();
        preg_match('/:root \{(.*?)\n\}/s', $css, $light);
        $tokens = self::declarations($light[1] ?? '');
        if ($theme === 'dark') {
            preg_match('/:root\[data-theme="dark"\] \{(.*?)\}/s', $css, $dark);
            $tokens = self::declarations($dark[1] ?? '') + $tokens;
        }

        return $tokens;
    }

    /**
     * @return array<string, string>
     */
    private static function declarations(string $block): array
    {
        preg_match_all('/--([a-z0-9-]+):\s*([^;]+);/', $block, $m, PREG_SET_ORDER);
        $tokens = [];
        foreach ($m as $match) {
            $tokens[$match[1]] = trim($match[2]);
        }

        return $tokens;
    }

    private static function css(): string
    {
        return (string) file_get_contents(Kernel::rootDir() . '/assets/css/app.css');
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} red, green, blue (0–1), alpha
     */
    private static function colour(string $value): array
    {
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $value, $m) === 1) {
            return [hexdec($m[1]) / 255, hexdec($m[2]) / 255, hexdec($m[3]) / 255, 1.0];
        }
        if (preg_match('/^rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\)$/', $value, $m) === 1) {
            return [(int) $m[1] / 255, (int) $m[2] / 255, (int) $m[3] / 255, (float) $m[4]];
        }
        self::fail('unreadable colour ' . $value);
    }

    /**
     * $colour composited over an opaque $background.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $colour
     * @param array{0: float, 1: float, 2: float} $background
     * @return array{0: float, 1: float, 2: float}
     */
    private static function over(array $colour, array $background): array
    {
        $alpha = $colour[3];

        return [
            $colour[0] * $alpha + $background[0] * (1 - $alpha),
            $colour[1] * $alpha + $background[1] * (1 - $alpha),
            $colour[2] * $alpha + $background[2] * (1 - $alpha),
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     */
    private static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    private static function luminance(array $rgb): float
    {
        $linear = array_map(
            static fn (float $c): float => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            $rgb,
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
