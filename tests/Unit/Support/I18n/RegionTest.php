<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\I18n;

use Locale;
use Logbook\Support\I18n\Region;
use Logbook\Support\PlateStyle;
use PHPUnit\Framework\TestCase;

/**
 * An empty or blank locale has no region, whatever the server's default locale (spec.md §7.14,
 * Phase 54.21, K223, #645).
 */
final class RegionTest extends TestCase
{
    private string $default;

    protected function setUp(): void
    {
        // A server whose own locale is en_GB: ICU reads an empty locale as this default.
        $this->default = Locale::getDefault();
        Locale::setDefault('en_GB');
    }

    protected function tearDown(): void
    {
        Locale::setDefault($this->default);
    }

    public function testAnEmptyOrBlankLocaleHasNoRegionOnAnEnGbServer(): void
    {
        self::assertNull(Region::of(''));
        self::assertNull(Region::of('  '));
        self::assertSame(PlateStyle::Neutral, PlateStyle::forLocale(''));
    }

    public function testALocaleReadsAsBeforeOnAnEnGbServer(): void
    {
        self::assertSame('GB', Region::of('en_GB'));
        self::assertNull(Region::of('en'));
        self::assertNull(Region::of('de'));
    }
}
