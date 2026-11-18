<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Process;

use Logbook\Support\Process\Executable;
use Logbook\Support\Process\NullDevice;
use Logbook\Support\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Starting Tesseract and Ghostscript on a bare-PHP Windows server (spec.md §9, Phase 54.19, K213):
 * the null device is `NUL` there (#635), and the binary lookup finds `tesseract.exe` from a bare
 * `tesseract` and takes a backslash path (#642). The OS is handed in, so Linux runs check the
 * Windows rules too; one test starts a real process on whichever OS runs the suite.
 */
final class WindowsProcessTest extends TestCase
{
    private string $dir = '';
    private string|false $path = false;

    protected function setUp(): void
    {
        $this->path = getenv('PATH');
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logbook-exe-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        putenv($this->path === false ? 'PATH' : 'PATH=' . $this->path);
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testTheNullDeviceIsNulOnWindowsAndDevNullElsewhere(): void
    {
        self::assertSame('NUL', NullDevice::path('Windows'));
        self::assertSame('/dev/null', NullDevice::path('Linux'));
        self::assertSame('/dev/null', NullDevice::path('Darwin'));
        self::assertSame(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', NullDevice::path());
    }

    public function testAProcessStartsAndIsReadOnTheOsRunningTheTest(): void
    {
        // Failed on Windows before K213: proc_open couldn't open /dev/null, so nothing started.
        $result = (new ProcessRunner())->run([PHP_BINARY, '-v'], 10);

        self::assertTrue($result->ok(), $result->stderr);
        self::assertStringStartsWith('PHP ' . PHP_MAJOR_VERSION . '.', $result->stdout);
    }

    public function testOnWindowsABareNameIsFoundWithAnExtensionFromPathext(): void
    {
        $exe = $this->binary('tool.EXE');
        $this->onPath();

        self::assertSame($exe, Executable::find('tool', 'Windows', '.COM;.EXE;.BAT;.CMD'));
        self::assertSame($exe, Executable::find('tool.EXE', 'Windows', '.COM;.EXE'), 'a name with its extension, as given');
        self::assertNull(Executable::find('tool', 'Windows', '.COM;.BAT'), 'not an extension PATHEXT names');
        self::assertNull(Executable::find('tool', 'Linux'), 'elsewhere no extension is added');
    }

    public function testOnWindowsAnEmptyPathextFallsBackToTheUsualExtensions(): void
    {
        $cmd = $this->binary('launcher.CMD');
        $this->onPath();

        self::assertSame($cmd, Executable::find('launcher', 'Windows', ''));
        self::assertSame($cmd, Executable::find('launcher', 'Windows', ' ; ;'));
    }

    public function testOnWindowsOnlyAPathextExtensionMakesAFileRunnable(): void
    {
        // No execute bit to go by (and is_executable() is false there for a .cmd): Windows runs
        // a file by its extension, so one with none, or another, is passed over.
        $this->binary('both');
        $exe = $this->binary('both.EXE');
        $this->binary('notes.txt');
        $this->onPath();

        self::assertSame($exe, Executable::find('both', 'Windows', '.com;.Exe'), 'ignoring case');
        self::assertNull(Executable::find('notes.txt', 'Windows', '.EXE'));
        self::assertNull(Executable::find($this->dir . DIRECTORY_SEPARATOR . 'notes.txt', 'Windows', '.EXE'));
        self::assertSame($exe, Executable::find($this->dir . DIRECTORY_SEPARATOR . 'both.EXE', 'Windows', '.EXE'));
    }

    public function testOnWindowsABackslashOrDriveLetterMakesItAPath(): void
    {
        self::assertNull(Executable::find('C:\\No Such\\tesseract.exe', 'Windows', '.EXE'));
        self::assertNull(Executable::find('C:tesseract.exe', 'Windows', '.EXE'));
        self::assertNull(Executable::find('sub\\tool', 'Windows', '.EXE'));

        // Elsewhere a backslash is part of a name and is looked up on PATH; a full path keeps working.
        $odd = $this->binary('odd\\name');
        $this->onPath();
        self::assertSame($odd, Executable::find('odd\\name', 'Linux'));
        self::assertSame(PHP_BINARY, Executable::find(PHP_BINARY));
    }

    public function testEmptyEntriesOnPathAreSkipped(): void
    {
        $exe = $this->binary('tool.EXE');
        putenv('PATH=' . PATH_SEPARATOR . $this->dir . DIRECTORY_SEPARATOR . PATH_SEPARATOR);

        self::assertSame($exe, Executable::find('tool', 'Windows', '.EXE'), 'a trailing separator is trimmed');
    }

    private function binary(string $name): string
    {
        // Backslashes can't name a file on Windows; that case only runs elsewhere.
        if (PHP_OS_FAMILY === 'Windows' && str_contains($name, '\\')) {
            self::markTestSkipped('a backslash in a file name needs a POSIX filesystem');
        }
        $file = $this->dir . DIRECTORY_SEPARATOR . $name;
        // On Windows a .exe/.cmd name is what makes a file executable; elsewhere the mode bit is.
        file_put_contents($file, '');
        chmod($file, 0755);

        return $file;
    }

    private function onPath(): void
    {
        putenv('PATH=' . $this->dir);
    }
}
