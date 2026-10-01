<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\External;

use Logbook\Domain\Auth\OidcLinkMode;
use Logbook\Domain\Auth\ProxyLinkMode;
use Logbook\Domain\User\UserIdentity;
use Logbook\Support\Config\OidcConfig;
use Logbook\Support\Config\ProxyAuthConfig;

/**
 * The rules one way of signing in applies when finding the user (spec.md
 * §7.9): which identities are its own, whether an equal username links,
 * whether a new member is created, and its groups. The variable names are
 * for the log.
 */
final readonly class ExternalPolicy
{
    /**
     * @param list<string> $allowedGroups
     * @param list<string> $adminGroups
     */
    public function __construct(
        public string $provider,
        /** How the log names it: "Single sign-on", "Header sign-in". */
        public string $label,
        public bool $linkByUsername,
        public bool $autoCreate,
        public array $allowedGroups,
        public array $adminGroups,
        public string $allowedVariable,
        public string $adminVariable,
        /**
         * Log "not linked" and "not in the allowed groups" here. Header
         * sign-in asks on every request, so it logs these itself, throttled.
         */
        public bool $logRefusals = true,
    ) {
    }

    public static function oidc(OidcConfig $config): self
    {
        return new self(
            UserIdentity::OIDC,
            'Single sign-on',
            $config->link === OidcLinkMode::Username,
            $config->autoCreate,
            $config->allowedGroups,
            $config->adminGroups,
            'OIDC_ALLOWED_GROUPS',
            'OIDC_ADMIN_GROUPS',
        );
    }

    public static function proxy(ProxyAuthConfig $config): self
    {
        return new self(
            UserIdentity::PROXY,
            'Header sign-in',
            $config->link === ProxyLinkMode::Username,
            $config->autoCreate,
            $config->allowedGroups,
            $config->adminGroups,
            'AUTH_PROXY_ALLOWED_GROUPS',
            'AUTH_PROXY_ADMIN_GROUPS',
            logRefusals: false,
        );
    }
}
