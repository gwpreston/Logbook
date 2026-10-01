<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

use InvalidArgumentException;
use Logbook\Domain\Auth\OidcLinkMode;

/**
 * Single sign-on settings (spec.md §7.9, §9 `OIDC_*`). Setting
 * `OIDC_ISSUER` switches SSO on; a half-set configuration stops the app at
 * start with a message naming the variable.
 */
final readonly class OidcConfig
{
    /**
     * @param list<string> $scopes
     * @param list<string> $allowedGroups
     * @param list<string> $adminGroups
     */
    public function __construct(
        /** Exactly as configured: compared byte for byte with `iss` (Authentik's ends in "/"). */
        public string $issuer = '',
        public string $clientId = '',
        public string $clientSecret = '',
        public string $providerName = 'SSO',
        public array $scopes = ['openid', 'profile', 'email'],
        public string $usernameClaim = 'preferred_username',
        public string $groupsClaim = 'groups',
        public OidcLinkMode $link = OidcLinkMode::Explicit,
        public bool $autoCreate = false,
        public array $allowedGroups = [],
        public array $adminGroups = [],
        public bool $logout = false,
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        $issuer = $env->string('OIDC_ISSUER');
        if ($issuer === '') {
            return new self();
        }
        if (preg_match('~^https?://[^/\s?#]+(/[^\s?#]*)?$~i', $issuer) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'OIDC_ISSUER "%s" is not an http(s) URL (expected e.g. https://auth.example.com/application/o/logbook/).',
                $issuer,
            ));
        }
        foreach (['OIDC_CLIENT_ID', 'OIDC_CLIENT_SECRET'] as $required) {
            if (!$env->has($required)) {
                throw new InvalidArgumentException(sprintf('%s must be set when OIDC_ISSUER is set.', $required));
            }
        }

        $scopes = self::list($env->string('OIDC_SCOPES', 'openid profile email'), '/[\s,]+/');
        if (!in_array('openid', $scopes, true)) {
            throw new InvalidArgumentException('OIDC_SCOPES must include "openid".');
        }
        $link = OidcLinkMode::tryFrom(strtolower($env->string('OIDC_LINK', OidcLinkMode::Explicit->value)));
        if ($link === null) {
            throw new InvalidArgumentException(sprintf(
                'OIDC_LINK "%s" is invalid (expected explicit or username).',
                $env->string('OIDC_LINK'),
            ));
        }

        return new self(
            issuer: $issuer,
            clientId: $env->string('OIDC_CLIENT_ID'),
            clientSecret: $env->string('OIDC_CLIENT_SECRET'),
            providerName: mb_substr($env->string('OIDC_PROVIDER_NAME', 'SSO'), 0, 40),
            scopes: $scopes,
            usernameClaim: $env->string('OIDC_USERNAME_CLAIM', 'preferred_username'),
            groupsClaim: $env->string('OIDC_GROUPS_CLAIM', 'groups'),
            link: $link,
            autoCreate: $env->bool('OIDC_AUTO_CREATE', false),
            allowedGroups: self::list($env->string('OIDC_ALLOWED_GROUPS'), '/\s*,\s*/'),
            adminGroups: self::list($env->string('OIDC_ADMIN_GROUPS'), '/\s*,\s*/'),
            logout: $env->bool('OIDC_LOGOUT', false),
        );
    }

    public function isConfigured(): bool
    {
        return $this->issuer !== '';
    }

    /**
     * Whether the groups claim matters (a groups variable is set).
     */
    public function usesGroups(): bool
    {
        return $this->allowedGroups !== [] || $this->adminGroups !== [];
    }

    /**
     * @param non-empty-string $separator a regular expression
     * @return list<string>
     */
    private static function list(string $value, string $separator): array
    {
        $items = preg_split($separator, trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map('trim', $items === false ? [] : $items)));
    }
}
