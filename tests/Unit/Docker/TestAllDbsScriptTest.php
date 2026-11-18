<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Docker;

use PHPUnit\Framework\TestCase;

/**
 * `bin/test-all-dbs.sh` (spec.md §9, CONTRIBUTING.md): each engine's pass starts from an empty
 * database. The server engines are recreated before their pass (K155); the SQLite pass removes
 * its own file before `phinx migrate` (K211), so a second run in a row doesn't stop in the 2.0
 * migration's rollback on the users the first run's tests left behind.
 *
 * The script is run against a stand-in `docker` that records the command each test container
 * would run, and that command is then run in a scratch folder with stand-in `phinx` and `phpunit`.
 */
final class TestAllDbsScriptTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const SQLITE = 'var/testing-all-dbs.sqlite';

    private string $dir = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('needs a POSIX sh');
        }
        $this->dir = sys_get_temp_dir() . '/logbook-test-all-dbs-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0777, true);
        mkdir($this->dir . '/work/var', 0777, true);
        mkdir($this->dir . '/work/vendor/bin', 0777, true);

        // The stand-in docker: `ps` lists no running test container, `compose run` writes the
        // command it was given (the argument after `sh -c`) to commands.log, and the rest succeed.
        $this->script('/bin/docker', <<<'SH'
            #!/bin/sh
            [ "$1" = ps ] && exit 0
            while [ $# -gt 0 ]; do
                if [ "$1" = sh ] && [ "${2:-}" = -c ]; then
                    printf '%s\n---\n' "$3" >> "$LOG_DIR/commands.log"
                    exit 0
                fi
                shift
            done
            exit 0
            SH);

        // The stand-in phinx refuses the rollback while the earlier run's file is still there,
        // as the 2.0 migration does when it finds the users that run's tests added.
        $this->script('/work/vendor/bin/phinx', <<<'SH'
            #!/bin/sh
            echo "$1" >> ../steps.log
            db=var/testing-all-dbs.sqlite
            if [ "$1" = rollback ] && grep -q 'left over' "$db" 2>/dev/null; then
                echo 'This install has 7 users' >&2
                exit 1
            fi
            [ "$1" = migrate ] && [ ! -e "$db" ] && : > "$db"
            exit 0
            SH);
        $this->script('/work/vendor/bin/phpunit', "#!/bin/sh\necho phpunit >> ../steps.log\n");
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testTheSqlitePassRemovesItsFileBeforeMigrating(): void
    {
        $commands = $this->runScript('sqlite');
        self::assertCount(1, $commands);

        // What an earlier complete run leaves: the file (with its users) and SQLite's journals.
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            file_put_contents($this->dir . '/work/' . self::SQLITE . $suffix, 'left over');
        }

        self::assertSame(0, $this->inContainer($commands[0]), 'the second run passes');
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            self::assertFileDoesNotExist($this->dir . '/work/' . self::SQLITE . $suffix);
        }
        self::assertSame('', file_get_contents($this->dir . '/work/' . self::SQLITE), 'a fresh file');
        self::assertSame("migrate\nrollback\nmigrate\nphpunit\n", file_get_contents($this->dir . '/steps.log'));
    }

    public function testTheServerPassesLeaveTheSqliteFileAlone(): void
    {
        $commands = $this->runScript('pgsql mysql mariadb');
        self::assertCount(3, $commands);

        foreach ($commands as $command) {
            self::assertStringNotContainsString(self::SQLITE, $command);
            self::assertStringStartsWith('vendor/bin/phinx migrate', ltrim($command));
        }
    }

    public function testTheScriptIsValidShell(): void
    {
        exec('sh -n ' . escapeshellarg(self::ROOT . '/bin/test-all-dbs.sh') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }

    /**
     * Runs the script for the given engines with the stand-in docker first on PATH; it must pass.
     *
     * @return list<string> the command each test container would run, in order
     */
    private function runScript(string $engines): array
    {
        $env = 'PATH=' . escapeshellarg($this->dir . '/bin:' . (string) getenv('PATH'))
            . ' LOG_DIR=' . escapeshellarg($this->dir);
        $engineArgs = implode(' ', array_map('escapeshellarg', explode(' ', $engines)));
        exec($env . ' sh ' . escapeshellarg(self::ROOT . '/bin/test-all-dbs.sh') . ' ' . $engineArgs . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        $log = (string) @file_get_contents($this->dir . '/commands.log');

        return array_values(array_filter(explode("\n---\n", $log), static fn (string $c): bool => trim($c) !== ''));
    }

    /** Runs one recorded container command in the scratch app folder, as the container would. */
    private function inContainer(string $command): int
    {
        exec('cd ' . escapeshellarg($this->dir . '/work') . ' && sh -c ' . escapeshellarg($command) . ' 2>&1', $output, $status);

        return $status;
    }

    private function script(string $path, string $body): void
    {
        file_put_contents($this->dir . $path, $body);
        chmod($this->dir . $path, 0755);
    }
}
