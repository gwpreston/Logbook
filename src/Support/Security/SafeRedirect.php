<?php

declare(strict_types=1);

namespace Logbook\Support\Security;

/**
 * Guards "return to" targets (e.g. `?next=` after sign-in) against open
 * redirects: only paths inside this app are accepted.
 */
final class SafeRedirect
{
    /**
     * Return $target when it is a local path under $basePath (with optional
     * query string), otherwise null.
     */
    public static function localPath(?string $target, string $basePath): ?string
    {
        if ($target === null || $target === '' || strlen($target) > 2000) {
            return null;
        }

        // Absolute path only: no scheme, no host, no protocol-relative "//",
        // no backslashes (browsers treat "/\evil" as "//evil"), no control characters.
        if (
            $target[0] !== '/'
            || str_starts_with($target, '//')
            || str_contains($target, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $target) === 1
            || str_contains($target, '#')
        ) {
            return null;
        }

        $path = (string) parse_url($target, PHP_URL_PATH);
        if ($basePath !== '' && $path !== $basePath && !str_starts_with($path, $basePath . '/')) {
            return null;
        }

        return $target;
    }
}
