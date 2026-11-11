<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Docker;

use PHPUnit\Framework\TestCase;

/**
 * bin/clear-cache.php (spec.md §10, K188, #610–#612): `composer install`
 * empties the compiled templates and translation catalogues, so a bare-PHP
 * upgrade shows the new release, and it never fails the install.
 */
final class ClearCacheTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/logbook-clear-cache-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Give back write access first, so a locked directory can be removed.
        @chmod($this->dir . '/twig', 0755);
        $this->remove($this->dir);
    }

    public function testOnlyTheTemplateAndTranslationCachesAreEmptied(): void
    {
        $this->put('twig/ab/cd.php');
        $this->put('translations/catalogue.en.php');
        $this->put('staged/import.csv');
        $this->put('oidc/discovery.json');
        $this->put('scheduled-tasks.lock');

        self::assertSame([0, ''], $this->clear());

        self::assertDirectoryExists($this->dir . '/twig');
        self::assertSame([], $this->listOf('twig'));
        self::assertSame([], $this->listOf('translations'));
        self::assertFileExists($this->dir . '/staged/import.csv');
        self::assertFileExists($this->dir . '/oidc/discovery.json');
        self::assertFileExists($this->dir . '/scheduled-tasks.lock');
    }

    public function testAMissingCacheIsNotAnError(): void
    {
        self::assertSame([0, ''], $this->clear());
    }

    public function testAFileItCannotDeleteIsAWarningNotAFailure(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            self::markTestSkipped('needs POSIX permissions and a user that is not root');
        }
        $this->put('twig/ab/cd.php');
        chmod($this->dir . '/twig', 0555);

        [$code, $stderr] = $this->clear();

        self::assertSame(0, $code);
        self::assertStringContainsString('Could not clear ' . $this->dir . '/twig: delete it by hand', $stderr);
        self::assertStringContainsString('run bin/clear-cache.php as the web server user.', $stderr);
    }

    public function testACachePathThatIsAFileStillExitsZero(): void
    {
        $file = $this->dir . '/not-a-directory';
        touch($file);

        self::assertSame(0, $this->clear($file)[0]);
    }

    public function testComposerRunsItOnInstallAndUpdate(): void
    {
        /** @var array{scripts: array<string, string|list<string>>} $composer */
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('@php bin/clear-cache.php', $composer['scripts']['post-install-cmd']);
        self::assertSame('@php bin/clear-cache.php', $composer['scripts']['post-update-cmd']);
    }

    /**
     * Run the script against $cacheDir (the temporary directory by default).
     *
     * @return array{0: int, 1: string} [exit code, stderr]
     */
    private function clear(?string $cacheDir = null): array
    {
        $process = proc_open(
            [PHP_BINARY, self::ROOT . '/bin/clear-cache.php', '--cache-dir=' . ($cacheDir ?? $this->dir)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stderr];
    }

    private function put(string $relative): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, 'x');
    }

    /**
     * @return list<string>
     */
    private function listOf(string $relative): array
    {
        return array_values(array_diff(scandir($this->dir . '/' . $relative) ?: [], ['.', '..']));
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                $this->remove($path . '/' . $entry);
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
