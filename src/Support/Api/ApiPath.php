<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

/**
 * Whether a request path is under the REST API (spec.md §7.20), with or
 * without the base path: middleware outside BasePathMiddleware may see it
 * either way, depending on the proxy.
 */
final class ApiPath
{
    public const string PREFIX = '/api/';
    /** The MCP endpoint (spec.md §7.28), which shares the API's CORS. */
    public const string MCP = '/mcp';

    public static function matches(string $path, string $basePath): bool
    {
        return str_starts_with($path, $basePath . self::PREFIX) || str_starts_with($path, self::PREFIX);
    }

    public static function isMcp(string $path, string $basePath): bool
    {
        return $path === self::MCP || ($basePath !== '' && $path === $basePath . self::MCP);
    }
}
