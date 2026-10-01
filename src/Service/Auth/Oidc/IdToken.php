<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

/**
 * A validated ID token: the raw token (for `id_token_hint` at sign-out)
 * and its claims.
 */
final readonly class IdToken
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public string $raw,
        public string $issuer,
        public string $subject,
        public array $claims,
    ) {
    }
}
