<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The checks bin/tools/check-docs.php runs in CI (spec.md §10 *README and
 * docs*, Phase 52): every relative link, anchor and image in the README,
 * CONTRIBUTING.md and docs/ resolves; every guide in docs/ is linked from
 * docs/README.md; and every image in docs/images/ is used. docs/phases/ is
 * left out (MarkdownLinksTest still checks its links).
 */
final class DocsCheck
{
    private const INDEX = 'docs/README.md';
    private const IMAGES = 'docs/images';

    public function __construct(private readonly MarkdownLinks $links = new MarkdownLinks())
    {
    }

    /**
     * @return list<string> one line per problem, empty when the docs are fine
     */
    public function problems(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $files = $this->files($root);
        $problems = [];
        $targets = [];
        foreach ($files as $file) {
            array_push($problems, ...$this->links->broken($file, $root));
            foreach ($this->links->links($file) as [, $target]) {
                $resolved = $this->resolve($file, $target);
                if ($resolved !== null) {
                    $targets[$resolved][] = $file;
                }
            }
        }

        $index = $root . '/' . self::INDEX;
        if (!is_file($index)) {
            $problems[] = self::INDEX . ' — missing: it is the docs index';
        }
        foreach ($this->guides($root) as $guide) {
            if (!in_array($index, $targets[$guide] ?? [], true)) {
                $problems[] = sprintf('%s — not linked from %s', $this->relative($guide, $root), self::INDEX);
            }
        }
        foreach ($this->images($root) as $image) {
            if (!isset($targets[$image])) {
                $problems[] = sprintf('%s — not used by the README, CONTRIBUTING.md or docs/', $this->relative($image, $root));
            }
        }

        return $problems;
    }

    /**
     * @return list<string> the Markdown files checked: the README, CONTRIBUTING.md and docs/ outside docs/phases/
     */
    public function files(string $root): array
    {
        $files = array_values(array_filter(
            [$root . '/README.md', $root . '/CONTRIBUTING.md'],
            'is_file',
        ));
        foreach ($this->walk($root . '/docs') as $file) {
            if (str_ends_with($file, '.md') && !str_starts_with($file, $root . '/docs/phases/')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return list<string> every guide the index must link: Markdown in docs/ outside docs/phases/, but the index
     */
    private function guides(string $root): array
    {
        return array_values(array_filter(
            $this->files($root),
            static fn (string $file): bool => str_starts_with($file, $root . '/docs/')
                && $file !== $root . '/' . self::INDEX,
        ));
    }

    /**
     * @return list<string>
     */
    private function images(string $root): array
    {
        return array_values(array_filter(
            $this->walk($root . '/' . self::IMAGES),
            static fn (string $file): bool => basename($file)[0] !== '.',
        ));
    }

    /**
     * @return list<string> files under $dir, sorted, with forward slashes
     */
    private function walk(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /** The local file a relative link points at, without its fragment; null for URLs and same-page anchors. */
    private function resolve(string $file, string $target): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1 || str_starts_with($target, '//')) {
            return null;
        }
        $path = rawurldecode(explode('#', $target, 2)[0]);
        if ($path === '' || str_starts_with($path, '/')) {
            return null;
        }
        $parts = [];
        foreach (explode('/', dirname($file) . '/' . $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        $prefix = str_starts_with($file, '/') ? '/' : '';

        return $prefix . implode('/', $parts);
    }

    private function relative(string $file, string $root): string
    {
        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }
}
