<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use JsonException;

/**
 * Builds URLs for files in `public/assets`, always under the base path and
 * with a content-hash query string (from `manifest.json`) for cache busting.
 */
final class AssetPackage
{
    /** @var array<string, string>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $basePath,
        private readonly string $assetsDir,
    ) {
    }

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        $url = $this->basePath . '/assets/' . $path;
        $version = $this->manifest()[$path] ?? null;

        return $version === null ? $url : $url . '?v=' . rawurlencode($version);
    }

    /**
     * Every built asset's URL (for the service worker to cache), except
     * those matching $exclude (a regular expression on the asset path).
     *
     * @return list<string>
     */
    public function urls(string $exclude = '/^$/'): array
    {
        $urls = [];
        foreach (array_keys($this->manifest()) as $path) {
            if (preg_match($exclude, $path) !== 1) {
                $urls[] = $this->url($path);
            }
        }

        return $urls;
    }

    /**
     * A short hash of every asset's version: changes whenever any asset does.
     */
    public function version(): string
    {
        return substr(hash('sha256', (string) json_encode($this->manifest())), 0, 12);
    }

    /**
     * @return array<string, string>
     */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $this->manifest = [];
        $file = $this->assetsDir . '/manifest.json';
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false) {
            return $this->manifest;
        }

        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->manifest;
        }

        if (is_array($decoded)) {
            foreach ($decoded as $asset => $hash) {
                if (is_string($asset) && is_string($hash)) {
                    $this->manifest[$asset] = $hash;
                }
            }
        }

        return $this->manifest;
    }
}
