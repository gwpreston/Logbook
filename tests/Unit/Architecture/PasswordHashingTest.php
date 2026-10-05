<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Architecture;

use Logbook\Kernel;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every password is hashed by PasswordHasher (spec.md §7.9: Argon2id
 * through password_hash(), checked with password_verify()). Nothing else in
 * the app, its migrations, seeds or scripts hashes one by itself, or with
 * an unsuitable function.
 */
final class PasswordHashingTest extends TestCase
{
    private const string HASHER = 'src/Support/Security/PasswordHasher.php';

    public function testOnlyPasswordHasherHashesPasswords(): void
    {
        $offenders = [];
        foreach (['src', 'db', 'bin'] as $directory) {
            foreach (self::phpFiles(Kernel::rootDir() . '/' . $directory) as $relative => $code) {
                if ($relative === self::HASHER) {
                    continue;
                }
                if (preg_match('/\b(password_hash|password_verify|password_needs_rehash|crypt|md5|sha1)\s*\(/', $code, $m) === 1) {
                    $offenders[] = $relative . ': ' . $m[1] . '()';
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testTheHasherUsesArgon2id(): void
    {
        $code = (string) file_get_contents(Kernel::rootDir() . '/' . self::HASHER);

        self::assertStringContainsString('password_hash($password, PASSWORD_ARGON2ID', $code);
        self::assertStringContainsString('password_needs_rehash($hash, PASSWORD_ARGON2ID', $code);
    }

    /**
     * @return iterable<string, string> path relative to the root => code
     */
    private static function phpFiles(string $directory): iterable
    {
        $root = Kernel::rootDir() . '/';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen($root)) => (string) file_get_contents($file->getPathname());
            }
        }
    }
}
