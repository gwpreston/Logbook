<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Version;

use Logbook\Support\Version\SemVer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Release numbers as the update check compares them (spec.md §7.31).
 */
final class SemVerTest extends TestCase
{
    public function testTheVPrefixIsOptional(): void
    {
        self::assertSame('2.12.0', (string) SemVer::parse('v2.12.0'));
        self::assertSame(0, self::v('v2.12.0')->compare(self::v('2.12.0')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function newer(): iterable
    {
        yield 'patch' => ['2.11.3', '2.11.2'];
        yield 'minor over a higher patch' => ['v2.12.0', '2.11.3'];
        yield 'major' => ['3.0.0', '2.99.99'];
        yield 'numeric, not text' => ['2.10.0', '2.9.0'];
        yield 'a release over its dev build' => ['2.11.0', '2.11.0-dev'];
        yield 'a dev build over the last release' => ['2.11.0-dev', '2.10.0'];
    }

    #[DataProvider('newer')]
    public function testOrdering(string $newer, string $older): void
    {
        self::assertTrue(self::v($newer)->isNewerThan(self::v($older)));
        self::assertFalse(self::v($older)->isNewerThan(self::v($newer)));
    }

    public function testParseKeepsTheSuffix(): void
    {
        $dev = self::v('2.11.0-dev');
        self::assertSame('dev', $dev->suffix);
        self::assertSame('2.11.0-dev', (string) $dev);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notVersions(): iterable
    {
        yield 'two parts' => ['2.11'];
        yield 'text' => ['latest'];
        yield 'leading zero' => ['02.11.0'];
        yield 'markup' => ['2.11.0-<b>'];
        yield 'empty' => [''];
    }

    #[DataProvider('notVersions')]
    public function testNotAVersion(string $value): void
    {
        self::assertNull(SemVer::parse($value) ?? SemVer::parseRelease($value));
    }

    public function testAReleaseTagHasNoSuffix(): void
    {
        self::assertSame('2.12.0', (string) SemVer::parseRelease('v2.12.0'));
        self::assertNull(SemVer::parseRelease('v2.12.0-rc.1'));
        self::assertNull(SemVer::parseRelease("v2.12.0\n"));
    }

    private static function v(string $value): SemVer
    {
        $version = SemVer::parse($value);
        self::assertNotNull($version, $value);

        return $version;
    }
}
