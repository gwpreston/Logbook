<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Docs;

use Logbook\Tests\Support\MarkdownLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every relative link in the repository's Markdown resolves (docs/phases/
 * phase-20.md): the target exists, and a #fragment on a Markdown target
 * names one of its headings. So moving or renaming a file, or rewording a
 * heading, fails here with the file, line and link.
 */
final class MarkdownLinksTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/{,*/}*.md', GLOB_BRACE) ?: []);
            @rmdir($this->dir . '/sub');
            @rmdir($this->dir);
        }
    }

    public function testEveryRelativeLinkInTheRepositoryResolves(): void
    {
        $checker = new MarkdownLinks();
        $root = (string) realpath(self::ROOT);
        $files = $checker->files($root);
        $problems = [];
        foreach ($files as $file) {
            array_push($problems, ...$checker->broken($file, $root));
        }

        self::assertContains($root . '/docs/phases/phase-20.md', $files, 'the scan reaches docs/phases/');
        self::assertSame([], $problems, "Broken Markdown links:\n" . implode("\n", $problems));
    }

    public function testABrokenLinkOrFragmentIsReportedWithFileAndLine(): void
    {
        $this->write('sub/target.md', "# Target\n\n## Moving a set — between vehicles\n");
        $this->write('page.md', implode("\n", [
            '# Page',
            'Fine: [t](sub/target.md), [dir](sub/), [h](sub/target.md#moving-a-set--between-vehicles),',
            '[self](#page), [web](https://example.com/missing.md), [mail](mailto:a@example.com).',
            'Broken: [gone](missing.md)',
            'and [frag](sub/target.md#no-such-heading).',
            '`[in code](also-missing.md)` is not a link.',
            '```',
            '[fenced](missing-too.md)',
            '```',
            '[ref]: sub/gone.md',
        ]));

        $problems = (new MarkdownLinks())->broken($this->dir . '/page.md', $this->dir);

        self::assertSame([
            'page.md:4: missing.md — no such file',
            'page.md:5: sub/target.md#no-such-heading — no heading #no-such-heading',
            'page.md:10: sub/gone.md — no such file',
        ], $problems);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headings(): iterable
    {
        yield 'plain' => ['Health check', 'health-check'];
        yield 'dash and punctuation' => ['Phase 20 — Phase files into `docs/phases/`, open-questions review',
            'phase-20--phase-files-into-docsphases-open-questions-review'];
        yield 'link text' => ['The [REST API](docs/api.md) behind a proxy', 'the-rest-api-behind-a-proxy'];
        yield 'emphasis and snake case' => ['*Keep* the `api_keys` table', 'keep-the-api_keys-table'];
        yield 'numbered' => ['12. Phases and open questions', '12-phases-and-open-questions'];
    }

    #[DataProvider('headings')]
    public function testHeadingsAreSluggedLikeGitHub(string $heading, string $slug): void
    {
        self::assertSame($slug, MarkdownLinks::slug($heading));
    }

    public function testRepeatedHeadingsGetNumberedAnchors(): void
    {
        $this->write('page.md', "# Tasks\n## Tasks\n```\n# Tasks\n```\n<a id=\"custom\"></a>\n### Tasks\n");

        self::assertSame(['tasks', 'tasks-1', 'custom', 'tasks-2'], (new MarkdownLinks())->anchors($this->dir . '/page.md'));
    }

    private function write(string $name, string $contents): void
    {
        if ($this->dir === '') {
            $this->dir = sys_get_temp_dir() . '/logbook-md-' . bin2hex(random_bytes(4));
            mkdir($this->dir . '/sub', 0777, true);
        }
        file_put_contents($this->dir . '/' . $name, $contents);
    }
}
