<?php

declare(strict_types=1);

namespace Logbook\Service\Mcp;

/**
 * The MCP protocol versions Logbook speaks (spec.md §7.28, decided
 * 2026-10-01, #89): the stateless `2026-07-28`, and the `initialize`
 * versions before it, served statelessly too.
 */
final class McpVersion
{
    public const string MODERN = '2026-07-28';
    /** Newest first: an `initialize` asking for another version gets the first. */
    public const array LEGACY = ['2025-11-25', '2025-06-18'];
    /** A legacy request without `MCP-Protocol-Version`. */
    public const string LEGACY_UNSTATED = '2025-06-18';

    /**
     * @return list<string> every version, newest first
     */
    public static function supported(): array
    {
        return [self::MODERN, ...self::LEGACY];
    }

    public static function isLegacy(string $version): bool
    {
        return in_array($version, self::LEGACY, true);
    }

    /**
     * Whether a version string names one at or after the stateless revision
     * (versions are dates, so they compare as strings).
     */
    public static function isModernEra(string $version): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) === 1 && strcmp($version, self::MODERN) >= 0;
    }
}
