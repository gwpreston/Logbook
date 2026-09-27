<?php

declare(strict_types=1);

namespace Logbook\Domain\User;

/**
 * Usernames are stored lower-case so sign-in is case-insensitive on every
 * engine (PostgreSQL compares case-sensitively, MySQL usually does not).
 */
final class Username
{
    public const int MIN_LENGTH = 3;
    public const int MAX_LENGTH = 64;
    /** Letters (any script), digits and . _ - @ */
    public const string PATTERN = '/^[\p{L}\p{N}._@-]+$/u';

    public static function normalise(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    public static function isValid(string $normalised): bool
    {
        $length = mb_strlen($normalised);

        return $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $normalised) === 1;
    }
}
