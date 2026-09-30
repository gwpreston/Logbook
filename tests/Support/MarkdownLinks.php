<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Checks the relative links in the repository's Markdown: every inline link
 * or reference definition outside code must point at a file or directory
 * that exists, and a fragment (#heading) on a Markdown target must match one
 * of its headings as GitHub slugs them. External URLs are not fetched.
 */
final class MarkdownLinks
{
    /** Directories never scanned, at any depth. Hidden ones are skipped too, except .github. */
    private const SKIP = ['vendor', 'node_modules', 'var'];

    /** @var array<string, list<string>> anchors per Markdown file, by real path */
    private array $anchors = [];

    /**
     * @return list<string> every *.md under $root outside the skipped directories
     */
    public function files(string $root): array
    {
        $dirs = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file): bool {
                $name = $file->getFilename();
                if (!$file->isDir()) {
                    return str_ends_with($name, '.md');
                }

                return !in_array($name, self::SKIP, true) && ($name[0] !== '.' || $name === '.github');
            },
        );
        $files = [];
        foreach (new RecursiveIteratorIterator($dirs) as $file) {
            if ($file instanceof SplFileInfo) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string> one "file:line: link — reason" per broken link
     */
    public function broken(string $file, string $root): array
    {
        $problems = [];
        foreach ($this->links($file) as [$line, $target]) {
            $reason = $this->check($file, $target);
            if ($reason !== null) {
                $problems[] = sprintf('%s:%d: %s — %s', $this->relative($file, $root), $line, $target, $reason);
            }
        }

        return $problems;
    }

    /**
     * @return list<array{int, string}> line number and target of every link outside code
     */
    public function links(string $file): array
    {
        $links = [];
        $fence = null;
        foreach ($this->lines($file) as $index => $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                if ($fence === null) {
                    $fence = $m[1][0];
                } elseif ($m[1][0] === $fence) {
                    $fence = null;
                }
                continue;
            }
            if ($fence !== null) {
                continue;
            }
            $prose = (string) preg_replace('/(`+).*?\1/', '', $line);
            preg_match_all('/\]\(\s*(<[^>]*>|[^)\s]+)(?:\s+"[^"]*")?\s*\)/', $prose, $inline);
            preg_match_all('/^\s{0,3}\[[^\]]+\]:\s*(<[^>]*>|\S+)/', $prose, $reference);
            foreach ([...$inline[1], ...$reference[1]] as $target) {
                $links[] = [$index + 1, trim($target, '<>')];
            }
        }

        return $links;
    }

    /**
     * @return list<string> the fragment ids a Markdown file's headings and HTML anchors provide
     */
    public function anchors(string $file): array
    {
        $key = (string) realpath($file);
        if (isset($this->anchors[$key])) {
            return $this->anchors[$key];
        }
        $anchors = [];
        $seen = [];
        $fence = null;
        foreach ($this->lines($file) as $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                $fence = $fence === null ? $m[1][0] : ($m[1][0] === $fence ? null : $fence);
                continue;
            }
            if ($fence !== null) {
                continue;
            }
            if (preg_match('/^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/', $line, $m) === 1) {
                $slug = self::slug($m[1]);
                $count = $seen[$slug] ?? 0;
                $seen[$slug] = $count + 1;
                $anchors[] = $count === 0 ? $slug : $slug . '-' . $count;
            }
            preg_match_all('/<a\s+(?:id|name)="([^"]+)"/', $line, $html);
            array_push($anchors, ...$html[1]);
        }

        return $this->anchors[$key] = $anchors;
    }

    /** GitHub's heading id: the rendered text, lower-cased, punctuation dropped, spaces as hyphens. */
    public static function slug(string $heading): string
    {
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
        $text = str_replace(['`', '*'], '', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])_+|_+(?![\p{L}\p{N}])/u', '', $text);
        $text = mb_strtolower(strip_tags($text));
        $text = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $text);

        return str_replace(' ', '-', $text);
    }

    private function check(string $file, string $target): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1 || str_starts_with($target, '//')) {
            return null;
        }
        [$path, $fragment] = array_pad(explode('#', $target, 2), 2, null);
        $path = rawurldecode((string) $path);
        if (str_starts_with($path, '/')) {
            return 'root-relative links break on GitHub; use a relative path';
        }
        $resolved = $path === '' ? $file : dirname($file) . '/' . $path;
        if (!file_exists($resolved)) {
            return 'no such file';
        }
        if ($fragment === null || $fragment === '' || !str_ends_with($resolved, '.md')) {
            return null;
        }
        if (!in_array(rawurldecode($fragment), $this->anchors($resolved), true)) {
            return 'no heading #' . $fragment;
        }

        return null;
    }

    /** @return list<string> */
    private function lines(string $file): array
    {
        $lines = preg_split('/\R/', (string) file_get_contents($file));

        return $lines === false ? [] : $lines;
    }

    private function relative(string $file, string $root): string
    {
        $root = rtrim($root, '/') . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
