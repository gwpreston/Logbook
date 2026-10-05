<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Every icon a template names literally (`ui.icon('…')`) is in the built
 * sprite, so none renders as an empty square (Phase 33.4's design review).
 */
final class IconSpriteTest extends TestCase
{
    public function testEveryLiteralIconIsInTheSprite(): void
    {
        $root = dirname(__DIR__, 3);
        $sprite = (string) file_get_contents($root . '/assets/vendor/icons.svg');
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/templates'));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }
            preg_match_all("/(?:ui\\.icon\\(|icon: ')'?([a-z0-9_]+)'/", (string) file_get_contents($file->getPathname()), $found);
            foreach ($found[1] as $name) {
                if (!str_contains($sprite, 'id="' . $name . '"')) {
                    $missing[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $name;
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)), 'add them to bin/vendor-assets.mjs and run npm run vendor');
    }
}
