<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use InvalidArgumentException;

/**
 * The URL prefix the app is served under when reverse-proxied at a subpath.
 *
 * Normalised form: either '' (served at the root) or '/segment[/segment…]'
 * with a leading slash and no trailing slash.
 */
final class BasePath
{
    public static function normalise(string $value): string
    {
        $trimmed = trim(trim($value), '/');
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('#^[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#', $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'APP_BASE_PATH "%s" is invalid: use URL path segments such as "/logbook".',
                $value,
            ));
        }

        return '/' . $trimmed;
    }

    /**
     * Return the request path with the base path guaranteed as its prefix.
     *
     * A reverse proxy may forward "/logbook/x" unchanged or strip it to "/x";
     * both are mapped to "/logbook/x" so routing and URL generation agree.
     * The bare base path ("/logbook") maps to its root ("/logbook/").
     */
    public static function ensurePrefixed(string $basePath, string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        if ($basePath === '') {
            return $path;
        }

        if ($path === $basePath) {
            return $basePath . '/';
        }

        if (str_starts_with($path, $basePath . '/')) {
            return $path;
        }

        return $basePath . $path;
    }
}
