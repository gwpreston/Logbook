<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Docs;

use Logbook\Tests\Support\DocsCheck;
use PHPUnit\Framework\TestCase;

/**
 * bin/tools/check-docs.php (spec.md §10 *README and docs*, Phase 52): a broken
 * link, an orphaned guide and an unused image each fail it, docs/phases/ is
 * left out, and the repository's own docs pass.
 */
final class DocsCheckTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testTheRepositoryDocsPass(): void
    {
        $root = (string) realpath(self::ROOT);

        self::assertSame([], (new DocsCheck())->problems($root));
    }

    public function testCleanDocsPass(): void
    {
        $this->fixture();

        self::assertSame([], (new DocsCheck())->problems($this->dir));
    }

    public function testABrokenLinkAnAnchorAndAMissingImageFail(): void
    {
        $this->fixture([
            'docs/guide.md' => "# Guide\n\n## Usage\n\nSee [gone](missing.md), [anchor](README.md#no-such-heading)"
                . " and ![shot](images/missing.png).\n\n[Back to the docs index](README.md)\n",
        ]);

        self::assertSame([
            'docs/guide.md:5: missing.md — no such file',
            'docs/guide.md:5: README.md#no-such-heading — no heading #no-such-heading',
            'docs/guide.md:5: images/missing.png — no such file',
        ], (new DocsCheck())->problems($this->dir));
    }

    public function testAGuideMissingFromTheIndexFails(): void
    {
        $this->fixture(['docs/orphan.md' => "# Orphan\n\nLinked only from the README.\n"]);
        file_put_contents(
            $this->dir . '/README.md',
            "# App\n\n![Shot](docs/images/shot.png) [Orphan](docs/orphan.md) · [docs](docs/README.md)\n",
        );

        self::assertSame(['docs/orphan.md — not linked from docs/README.md'], (new DocsCheck())->problems($this->dir));
    }

    public function testAnUnusedImageFailsAndPictureSourcesCountAsUsed(): void
    {
        $this->fixture([
            'docs/images/unused.png' => 'png',
            'docs/images/dark.png' => 'png',
            'docs/guide.md' => "# Guide\n\n## Usage\n\n<picture>\n"
                . "  <source media=\"(prefers-color-scheme: dark)\" srcset=\"images/dark.png\">\n"
                . "  <img src=\"images/shot.png\" alt=\"A shot\">\n</picture>\n",
        ]);

        self::assertSame(
            ['docs/images/unused.png — not used by the README, CONTRIBUTING.md or docs/'],
            (new DocsCheck())->problems($this->dir),
        );
    }

    public function testDocsPhasesAreLeftOut(): void
    {
        $this->fixture(['docs/phases/phase-1.md' => "# Phase 1\n\n[gone](missing.md)\n"]);

        self::assertSame([], (new DocsCheck())->problems($this->dir));
        self::assertNotContains($this->dir . '/docs/phases/phase-1.md', (new DocsCheck())->files($this->dir));
    }

    public function testAMissingIndexFails(): void
    {
        $this->fixture();
        unlink($this->dir . '/docs/README.md');
        file_put_contents($this->dir . '/README.md', "# App\n\n![Shot](docs/images/shot.png) [Guide](docs/guide.md)\n");

        self::assertSame([
            'CONTRIBUTING.md:3: docs/README.md#guides — no such file',
            'docs/guide.md:7: README.md — no such file',
            'docs/README.md — missing: it is the docs index',
            'docs/guide.md — not linked from docs/README.md',
        ], (new DocsCheck())->problems($this->dir));
    }

    public function testTheScriptExitsNonZeroWithTheProblems(): void
    {
        $this->fixture(['docs/orphan.md' => "# Orphan\n"]);

        [$code, $output] = $this->script($this->dir);
        self::assertSame(1, $code);
        self::assertStringContainsString('docs/orphan.md — not linked from docs/README.md', $output);

        unlink($this->dir . '/docs/orphan.md');
        [$code, $output] = $this->script($this->dir);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Docs OK: 4 Markdown files checked.', $output);
    }

    /**
     * A small repository whose docs pass, plus $extra files (path => contents).
     *
     * @param array<string, string> $extra
     */
    private function fixture(array $extra = []): void
    {
        $this->dir = sys_get_temp_dir() . '/logbook-docs-' . bin2hex(random_bytes(4));
        $files = [
            'README.md' => "# App\n\n![Shot](docs/images/shot.png)\n\nSee the [docs](docs/README.md).\n",
            'CONTRIBUTING.md' => "# Contributing\n\nRead [the docs](docs/README.md#guides).\n",
            'docs/README.md' => "# Docs\n\n## Guides\n\n- [Guide](guide.md#usage)\n",
            'docs/guide.md' => "# Guide\n\n## Usage\n\nUse it.\n\n[Back to the docs index](README.md)\n",
            'docs/images/shot.png' => 'png',
            ...$extra,
        ];
        foreach ($files as $path => $contents) {
            $file = $this->dir . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $contents);
        }
    }

    /**
     * @return array{int, string}
     */
    private function script(string $root): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/bin/tools/check-docs.php')
            . ' ' . escapeshellarg($root) . ' 2>&1';
        exec($command, $lines, $code);

        return [$code, implode("\n", $lines)];
    }
}
