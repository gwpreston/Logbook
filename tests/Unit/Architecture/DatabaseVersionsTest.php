<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Architecture;

use Logbook\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The dev stack tests the MariaDB that CI tests and spec.md §4 names
 * (K178, #596, #600), so a local "MariaDB" run is a version users run.
 */
final class DatabaseVersionsTest extends TestCase
{
    public function testDevComposeAndCiRunTheSameMariaDb(): void
    {
        $dev = self::images(self::read('docker-compose.dev.yml'));
        $ci = self::images(self::read('.github/workflows/ci.yml'));

        self::assertNotSame([], $dev, 'docker-compose.dev.yml names a MariaDB image');
        self::assertNotSame([], $ci, 'ci.yml names a MariaDB image');
        self::assertSame(array_unique($ci), array_unique($dev), 'the dev stack and CI run one MariaDB image');
    }

    public function testThatMariaDbIsTheVersionTheSpecNames(): void
    {
        $spec = self::read('spec.md');
        preg_match('/\bMariaDB (\d+\.\d+)\)/', $spec, $named);
        if (!isset($named[1])) {
            self::fail('spec §4 names the MariaDB version');
        }

        foreach (self::images(self::read('docker-compose.dev.yml')) as $image) {
            self::assertSame('mariadb:' . $named[1], $image);
        }
    }

    public function testTheDevMariaDbKeepsItsDataInAVolumeNamedForTheVersion(): void
    {
        $compose = self::read('docker-compose.dev.yml');
        preg_match('/mariadb:(\d+)\.(\d+)/', $compose, $version);
        if (!isset($version[1], $version[2])) {
            self::fail('docker-compose.dev.yml names a MariaDB version');
        }
        $volume = 'mariadb_' . $version[1] . '_' . $version[2];

        self::assertStringContainsString('- ' . $volume . ':/var/lib/mysql', $compose, 'the service mounts the volume');
        self::assertMatchesRegularExpression('/^volumes:\R(?:  \w+:\R)*  ' . $volume . ':/m', $compose, 'the volume is declared');
    }

    /**
     * @return list<string>
     */
    private static function images(string $text): array
    {
        preg_match_all('/\bmariadb:\d+(?:\.\d+)*\b/', $text, $found);

        return array_values(array_filter($found[0], static fn (string $image): bool => str_contains($image, '.')));
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(Kernel::rootDir() . '/' . $relative);
    }
}
