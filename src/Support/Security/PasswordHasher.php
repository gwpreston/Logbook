<?php

declare(strict_types=1);

namespace Logbook\Support\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Argon2id password hashing (CLAUDE.md §9), using PHP's default cost
 * parameters unless others are given.
 */
final readonly class PasswordHasher
{
    /**
     * A real Argon2id hash (PHP's default cost) of a discarded random string,
     * verified against when the username is unknown so both paths take the
     * same time.
     */
    private const string DUMMY_HASH =
        '$argon2id$v=19$m=65536,t=4,p=1$RVBSNnlBV0VlYmVDb2JOMA$kf2CpHg1Zo6mxcJuHbOtSlazS5AA3lDi5uuzla99M2I';

    /**
     * @param array{memory_cost?: int, time_cost?: int, threads?: int} $options
     */
    public function __construct(private array $options = [])
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new RuntimeException(
                'This PHP build lacks Argon2id support (PASSWORD_ARGON2ID); use a PHP built with argon2 or libsodium.',
            );
        }
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Burn the same time as a real verification when there is no hash to
     * check (unknown username), so response times do not reveal usernames.
     */
    public function verifyDummy(#[SensitiveParameter] string $password): void
    {
        password_verify($password, self::DUMMY_HASH);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }
}
