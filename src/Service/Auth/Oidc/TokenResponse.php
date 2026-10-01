<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

/**
 * The token endpoint's answer: the ID token (not yet validated) and the
 * access token, used only for one userinfo request when claims are missing.
 */
final readonly class TokenResponse
{
    public function __construct(
        public string $idToken,
        public ?string $accessToken,
    ) {
    }
}
