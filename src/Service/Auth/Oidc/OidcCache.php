<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

/**
 * Discovery documents and key sets under `var/cache/oidc` (spec.md §7.9:
 * 24 hours), one file per URL. Written atomically; a file that cannot be
 * read or decoded counts as missing.
 */
final readonly class OidcCache
{
    public const int TTL_SECONDS = 86400;

    public function __construct(private string $directory)
    {
    }

    /**
     * @return array<mixed>|null
     */
    public function get(string $url, int $now): ?array
    {
        $file = $this->file($url);
        if (!is_file($file)) {
            return null;
        }
        $contents = @file_get_contents($file);
        $entry = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($entry) || !is_int($entry['fetched_at'] ?? null) || !is_array($entry['data'] ?? null)) {
            return null;
        }
        if ($now - $entry['fetched_at'] >= self::TTL_SECONDS || $entry['fetched_at'] > $now) {
            return null;
        }

        return $entry['data'];
    }

    /**
     * @param array<mixed> $data
     */
    public function put(string $url, array $data, int $now): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }
        $file = $this->file($url);
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['fetched_at' => $now, 'url' => $url, 'data' => $data], JSON_UNESCAPED_SLASHES);
        if ($json !== false && @file_put_contents($temp, $json) !== false) {
            @rename($temp, $file);
        }
        @unlink($temp);
    }

    private function file(string $url): string
    {
        return $this->directory . '/' . hash('sha256', $url) . '.json';
    }
}
