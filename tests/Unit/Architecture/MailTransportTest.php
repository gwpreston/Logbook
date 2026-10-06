<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Architecture;

use Logbook\Kernel;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * One mail transport (spec.md §7.11 *One transport*, Phase 36.1): nothing
 * in the app but MailerFactory builds one, so nothing can send around the
 * saved settings or demo mode's guard; and nothing reads the removed
 * `MAIL_*` variables.
 */
final class MailTransportTest extends TestCase
{
    private const string FACTORY = 'src/Service/Mail/MailerFactory.php';
    private const string BUILDS = '/\bnew\s+\\\\?(?:[A-Za-z\\\\]*\\\\)?'
        . '(EsmtpTransport|SmtpTransport|SendmailTransport|NativeTransport|SocketStream)\b'
        . '|\bTransport::(fromDsn|fromDsns|fromDsnObject)\b|\bnew\s+\\\\?(?:[A-Za-z\\\\]*\\\\)?Transport\s*\(/';
    private const string OLD_VARIABLES = '/[\'"]MAIL_(HOST|PORT|USERNAME|PASSWORD|ENCRYPTION|FROM|TO)[\'"]/';

    public function testOnlyMailerFactoryBuildsATransport(): void
    {
        $offenders = [];
        foreach (['src', 'config', 'bin'] as $directory) {
            foreach (self::phpFiles(Kernel::rootDir() . '/' . $directory) as $relative => $code) {
                if ($relative !== self::FACTORY && preg_match(self::BUILDS, $code, $m) === 1) {
                    $offenders[] = $relative . ': ' . $m[0];
                }
            }
        }

        self::assertSame([], $offenders);
        self::assertMatchesRegularExpression(self::BUILDS, (string) file_get_contents(Kernel::rootDir() . '/' . self::FACTORY));
    }

    public function testNothingReadsTheRemovedMailVariables(): void
    {
        $offenders = [];
        foreach (['src', 'config', 'bin'] as $directory) {
            foreach (self::phpFiles(Kernel::rootDir() . '/' . $directory) as $relative => $code) {
                // The Delivery page names them while they are still set.
                if ($relative !== 'src/Service/Mail/EmailServerAdmin.php' && preg_match(self::OLD_VARIABLES, $code, $m) === 1) {
                    $offenders[] = $relative . ': ' . $m[0];
                }
            }
        }

        self::assertSame([], $offenders);
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
