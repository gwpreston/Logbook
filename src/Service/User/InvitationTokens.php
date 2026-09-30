<?php

declare(strict_types=1);

namespace Logbook\Service\User;

/**
 * Invitation tokens are stored as HMAC-SHA256 keyed with SESSION_SECRET,
 * like session ids, calendar tokens and API keys (spec.md §6 Invitation).
 */
final class InvitationTokens
{
    /** 32 random bytes, base64url without padding. */
    public const string PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public static function hash(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $token, $secret);
    }
}
