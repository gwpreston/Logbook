<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * One licence, stated the same way everywhere (spec.md §1, docs/phases/
 * phase-45.md), and bin/third-party-notices.php keeps THIRD-PARTY-NOTICES.md
 * honest: it fails on a stale file or a licence outside its allowlist.
 */
final class LicenceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const SPDX = 'AGPL-3.0-or-later';

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }
    }

    public function testEveryPlaceThatStatesTheLicenceAgrees(): void
    {
        $composer = json_decode($this->read('composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame(self::SPDX, $composer['license'] ?? null);

        $license = $this->read('LICENSE');
        self::assertStringStartsWith('GNU AFFERO GENERAL PUBLIC LICENSE', trim($license));
        self::assertStringContainsString('Version 3, 19 November 2007', $license);

        $label = 'org.opencontainers.image.licenses=';
        self::assertStringContainsString($label . '"' . self::SPDX . '"', $this->read('Dockerfile'));
        self::assertStringContainsString($label . self::SPDX, $this->read('.github/workflows/ci.yml'));
        self::assertStringContainsString('!THIRD-PARTY-NOTICES.md', $this->read('.dockerignore'), 'shipped in the image');

        $openapi = json_decode($this->read('docs/api/openapi.json'), true);
        self::assertIsArray($openapi);
        self::assertIsArray($openapi['info'] ?? null);
        self::assertIsArray($openapi['info']['license'] ?? null);
        self::assertSame(self::SPDX, $openapi['info']['license']['identifier'] ?? null);

        $readme = $this->read('README.md');
        self::assertStringContainsString('## Licence', $readme);
        self::assertStringContainsString('GNU Affero General Public License', $readme);
        self::assertStringContainsString('every published version', $readme);

        self::assertStringContainsString('`' . self::SPDX . '`', $this->read('spec.md'));
        self::assertStringContainsString('Signed-off-by', $this->read('CONTRIBUTING.md'));
    }

    public function testTheCommittedNoticesAreCurrent(): void
    {
        [$code, $output] = $this->notices('--check');

        self::assertSame(0, $code, $output);
    }

    public function testNoticesListShippedPackagesAndBundledFilesButNotDevTools(): void
    {
        $out = $this->fixture([
            ['name' => 'acme/lib', 'version' => '1.2.3', 'license' => ['MIT'], 'homepage' => 'https://acme.test'],
        ]);

        [$code, $output] = $this->notices($this->dir . '/composer.lock', $this->dir . '/vendor.txt', $out);

        self::assertSame(0, $code, $output);
        $notices = (string) file_get_contents($out);
        self::assertStringContainsString('| acme/lib | 1.2.3 | MIT | https://acme.test |', $notices);
        self::assertStringContainsString('| `x.min.js` | x (X Sprite) | 1.0.0 | Apache-2.0 | https://x.test |', $notices);
        self::assertStringNotContainsString('dev/tool', $notices);

        [$code] = $this->notices('--check', $this->dir . '/composer.lock', $this->dir . '/vendor.txt', $out);
        self::assertSame(0, $code);
    }

    public function testAPackageOfferingAnAllowedLicenceAmongOthersPasses(): void
    {
        $out = $this->fixture([['name' => 'acme/dual', 'version' => '1.0.0', 'license' => ['GPL-2.0-only', 'MIT']]]);

        [$code, $output] = $this->notices($this->dir . '/composer.lock', $this->dir . '/vendor.txt', $out);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('| acme/dual | 1.0.0 | GPL-2.0-only or MIT |', (string) file_get_contents($out));
    }

    public function testALicenceOutsideTheAllowlistFails(): void
    {
        $out = $this->fixture([
            ['name' => 'acme/gpl2', 'version' => '1.0.0', 'license' => ['GPL-2.0-only']],
            ['name' => 'acme/none', 'version' => '1.0.0'],
        ]);

        [$code, $output] = $this->notices($this->dir . '/composer.lock', $this->dir . '/vendor.txt', $out);

        self::assertSame(1, $code);
        self::assertStringContainsString('acme/gpl2: GPL-2.0-only', $output);
        self::assertStringContainsString('acme/none: no licence stated', $output);
        self::assertFileDoesNotExist($out);
    }

    public function testAStaleFileFailsTheCheck(): void
    {
        $out = $this->fixture([['name' => 'acme/lib', 'version' => '1.0.0', 'license' => ['MIT']]]);
        file_put_contents($out, "# Third-party notices\n\nout of date\n");

        [$code, $output] = $this->notices('--check', $this->dir . '/composer.lock', $this->dir . '/vendor.txt', $out);

        self::assertSame(1, $code);
        self::assertStringContainsString('is stale', $output);
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return string the output path
     */
    private function fixture(array $packages): string
    {
        $this->dir = sys_get_temp_dir() . '/logbook-notices-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/composer.lock', (string) json_encode([
            'packages' => $packages,
            'packages-dev' => [['name' => 'dev/tool', 'version' => '1.0.0', 'license' => ['proprietary']]],
        ]));
        file_put_contents(
            $this->dir . '/vendor.txt',
            "Third-party libraries bundled with Logbook\n\nx.min.js: x 1.0.0 (X Sprite) — Apache-2.0 license — https://x.test\n",
        );

        return $this->dir . '/NOTICES.md';
    }

    /** @return array{int, string} */
    private function notices(string ...$args): array
    {
        $command = [PHP_BINARY, self::ROOT . '/bin/third-party-notices.php', ...array_values($args)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(self::ROOT . '/' . $path);
        self::assertIsString($contents, $path);

        return $contents;
    }
}
