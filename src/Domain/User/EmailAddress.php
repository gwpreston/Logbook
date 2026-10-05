<?php

declare(strict_types=1);

namespace Logbook\Domain\User;

/**
 * A user's email address (spec.md §6 User `email`): stored trimmed and
 * lower-case, up to 254 characters, so matching is the same on every
 * engine.
 */
final class EmailAddress
{
    public const int MAX_LENGTH = 254;

    private function __construct()
    {
    }

    public static function normalise(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    /**
     * The normalised address, or null when it is not a valid one.
     */
    public static function parse(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }
        $normalised = self::normalise($address);

        return $normalised !== ''
            && mb_strlen($normalised) <= self::MAX_LENGTH
            && filter_var($normalised, FILTER_VALIDATE_EMAIL) !== false
            ? $normalised
            : null;
    }
}
