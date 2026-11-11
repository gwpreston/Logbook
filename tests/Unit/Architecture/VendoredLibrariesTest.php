<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bundled front-end libraries are the versions `package-lock.json`
 * names (spec §1, K183, #598, #605). `npm run vendor` writes each library's
 * version into `THIRD-PARTY-NOTICES.txt`, so comparing that file with the
 * lock catches a Dependabot npm pull request that bumped `package.json`
 * without re-vendoring. Reads files only: CI needs no Node for it.
 */
final class VendoredLibrariesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function noticesFiles(): array
    {
        // The source copy and the built copy the web server serves.
        return [
            'assets' => ['assets/vendor/THIRD-PARTY-NOTICES.txt'],
            'public' => ['public/assets/vendor/THIRD-PARTY-NOTICES.txt'],
        ];
    }

    #[DataProvider('noticesFiles')]
    public function testEveryBundledLibraryIsTheVersionThePackageLockNames(string $notices): void
    {
        $bundled = self::bundledVersions(self::read($notices));
        $locked = self::lockedVersions();

        self::assertNotSame([], $bundled, $notices . ' lists the bundled libraries');
        foreach ($bundled as $package => $version) {
            self::assertArrayHasKey($package, $locked, $package . ' is in package-lock.json');
            self::assertSame(
                $locked[$package],
                $version,
                $package . ' in ' . $notices . ' matches package-lock.json; re-vendor with `npm ci && npm run vendor`',
            );
        }
    }

    public function testEveryPinnedLibraryIsInTheNotices(): void
    {
        // Each devDependency but the maintainer-only tools (Playwright makes
        // the screenshots) is bundled, so each must be in the notices.
        $package = json_decode(self::read('package.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        $pinned = array_keys((array) ($package['devDependencies'] ?? []));
        $pinned = array_values(array_diff($pinned, ['playwright']));

        $bundled = array_keys(self::bundledVersions(self::read('assets/vendor/THIRD-PARTY-NOTICES.txt')));
        sort($pinned);
        sort($bundled);

        self::assertSame($pinned, $bundled);
    }

    /**
     * One notices line per bundled file, `<file>: <package> <version> …`;
     * several font files share one package.
     *
     * @return array<string, string> package name => version
     */
    private static function bundledVersions(string $notices): array
    {
        preg_match_all('/^[^:\r\n]+: (\S+) (\d+\.\d+\.\d+\S*) /m', $notices, $lines, PREG_SET_ORDER);

        $versions = [];
        foreach ($lines as $line) {
            $versions[$line[1]] = $line[2];
        }

        return $versions;
    }

    /**
     * @return array<string, string> package name => locked version
     */
    private static function lockedVersions(): array
    {
        $lock = json_decode(self::read('package-lock.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);

        $versions = [];
        foreach ((array) ($lock['packages'] ?? []) as $path => $entry) {
            // Top-level installs only (`node_modules/<name>`); nested copies
            // belong to other packages.
            $topLevel = preg_match('#^node_modules/((?:@[^/]+/)?[^/]+)$#', (string) $path, $name) === 1;
            if ($topLevel && is_array($entry) && is_string($entry['version'] ?? null)) {
                $versions[$name[1]] = $entry['version'];
            }
        }

        return $versions;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        self::assertIsString($contents, $path . ' is readable');

        return $contents;
    }
}
