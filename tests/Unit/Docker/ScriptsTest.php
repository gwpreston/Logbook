<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Docker;

use PHPUnit\Framework\TestCase;

/**
 * The scripts around the Docker stacks (spec.md §10, §11, Phase 53.9):
 * the smoke test runs under the compose project it is given (K121), and the
 * dev stack reinstalls vendor/ when composer.lock has changed (K128).
 */
final class ScriptsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const LOCK = "{\n    \"_readme\": [],\n    \"content-hash\": \"%s\",\n    \"packages\": []\n}\n";

    private string $dir = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('needs a POSIX sh');
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testTheSmokeTestRunsUnderTheProjectItIsGiven(): void
    {
        $script = (string) file_get_contents(self::ROOT . '/bin/smoke-test.sh');

        self::assertStringNotContainsString('-p logbook-smoke', $script);
        self::assertStringContainsString('project="${COMPOSE_PROJECT_NAME:-logbook-smoke}"', $script);
        self::assertSame(5, substr_count($script, 'docker compose -p $project '), 'every stack');
        self::assertStringNotContainsString('/tmp/smoke.', $script, 'nor share response files with another run');
        self::assertSame([0, ''], $this->shell('sh -n ' . escapeshellarg(self::ROOT . '/bin/smoke-test.sh')));
    }

    public function testTheDevStackInstallsWhenTheLockHasChanged(): void
    {
        $this->project('aaa111');

        // A vendor/ from before 53.9 has no record: install once, then record the lock.
        $changed = 'logbook: composer.lock has changed since vendor/ was installed; running composer install.';
        self::assertSame([0, $changed . "\ninstalled"], $this->entry());
        self::assertSame("aaa111\n", file_get_contents($this->dir . '/vendor/.composer-lock-hash'));

        self::assertSame([0, ''], $this->entry(), 'the same lock: nothing to do');

        file_put_contents($this->dir . '/composer.lock', sprintf(self::LOCK, 'bbb222'));
        self::assertSame(0, $this->entry()[0]);
        self::assertSame(2, $this->installs(), 'a pull changed the lock');
        self::assertSame("bbb222\n", file_get_contents($this->dir . '/vendor/.composer-lock-hash'));
    }

    public function testAnEmptyVendorVolumeIsInstalledAndRecorded(): void
    {
        $this->project('ccc333', installed: false);

        self::assertSame([0, 'installed'], $this->entry());
        self::assertSame("ccc333\n", file_get_contents($this->dir . '/vendor/.composer-lock-hash'));
    }

    public function testNothingIsInstalledOutsideDevelopment(): void
    {
        $this->project('ddd444', installed: false);

        self::assertSame([0, ''], $this->entry('production'));
        self::assertSame([0, ''], $this->entry(null));
        self::assertSame(0, $this->installs());

        $entrypoint = (string) file_get_contents(self::ROOT . '/docker/entrypoint.sh');
        self::assertMatchesRegularExpression(
            '~if \[ "\$\{APP_ENV:-production\}" = "development" \] && \[ -f docker/dev/composer-install.sh \]; then\n'
                . '\s+sh docker/dev/composer-install.sh\n~',
            $entrypoint,
            'the entrypoint runs it in development only',
        );
    }

    /** A copy of the app's root with a lock and, optionally, an installed vendor/; a fake composer on the PATH. */
    private function project(string $hash, bool $installed = true): void
    {
        $this->dir = sys_get_temp_dir() . '/logbook-dev-install-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/docker/dev', 0o777, true);
        mkdir($this->dir . '/bin');
        copy(self::ROOT . '/docker/dev/composer-install.sh', $this->dir . '/docker/dev/composer-install.sh');
        file_put_contents($this->dir . '/composer.lock', sprintf(self::LOCK, $hash));
        if ($installed) {
            mkdir($this->dir . '/vendor');
            touch($this->dir . '/vendor/autoload.php');
        }
        file_put_contents(
            $this->dir . '/bin/composer',
            "#!/bin/sh\nmkdir -p vendor && touch vendor/autoload.php && echo x >> installs && echo installed\n",
        );
        chmod($this->dir . '/bin/composer', 0o755);
    }

    /**
     * Runs the script as the entrypoint does, from the app's root.
     *
     * @return array{int, string}
     */
    private function entry(?string $appEnv = 'development'): array
    {
        $path = $this->dir . '/bin:/usr/bin:/bin';
        $env = 'env -i PATH=' . escapeshellarg($path) . ($appEnv === null ? '' : ' APP_ENV=' . escapeshellarg($appEnv));

        return $this->shell('cd ' . escapeshellarg($this->dir) . ' && ' . $env . ' sh docker/dev/composer-install.sh');
    }

    private function installs(): int
    {
        return substr_count((string) @file_get_contents($this->dir . '/installs'), 'x');
    }

    /** @return array{int, string} */
    private function shell(string $command): array
    {
        exec($command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }
}
