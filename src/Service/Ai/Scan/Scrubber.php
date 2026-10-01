<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

/**
 * Removes a registration document's reference number (spec.md §7.27):
 * any run of 11 digits, with or without spaces between them. Applied to
 * PDF text before it is sent, and to every string read back from a
 * registration document before it is shown or stored.
 */
final class Scrubber
{
    private const string ELEVEN_DIGITS = '/(?<![0-9])[0-9](?:[ \x{00A0}]?[0-9]){10}(?![0-9])/u';

    public static function text(string $text): string
    {
        return (string) preg_replace(self::ELEVEN_DIGITS, '', $text);
    }

    /**
     * Every string in a decoded JSON value.
     */
    public static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text($value);
        }
        if (is_array($value)) {
            return array_map(self::value(...), $value);
        }

        return $value;
    }

    public static function hasReference(string $text): bool
    {
        return preg_match(self::ELEVEN_DIGITS, $text) === 1;
    }
}
