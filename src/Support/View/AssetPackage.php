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
