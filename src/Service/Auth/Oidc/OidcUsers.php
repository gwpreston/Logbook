<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

use Logbook\Service\Auth\External\ExternalAccount;
use Logbook\Service\Auth\External\ExternalPolicy;
use Logbook\Service\Auth\External\ExternalUsers;
use Logbook\Support\Config\AppSettings;

/**
 * Who a validated provider account is in Logbook (spec.md §7.9 *Finding
 * the user*, *Groups*, *Linking*): the claims read with the OIDC_*
 * settings, then the rules header sign-in shares (ExternalUsers).
 */
final readonly class OidcUsers
{
    public function __construct(
        private AppSettings $settings,
        private ExternalUsers $users,
    ) {
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function resolve(string $issuer, string $subject, array $claims): OidcResult
    {
        return $this->users->resolve($this->policy(), $this->account($issuer, $subject, $claims));
    }

    /**
     * Link this provider account to a signed-in user (Settings → Account).
     *
     * @param array<string, mixed> $claims
     */
    public function link(int $userId, string $issuer, string $subject, array $claims): OidcResult
    {
        return $this->users->link($this->policy(), $userId, $this->account($issuer, $subject, $claims));
    }

    private function policy(): ExternalPolicy
    {
        return ExternalPolicy::oidc($this->settings->oidc);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function account(string $issuer, string $subject, array $claims): ExternalAccount
    {
        $config = $this->settings->oidc;
        $text = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return new ExternalAccount(
            $issuer,
            $subject,
            username: $text($claims[$config->usernameClaim] ?? null),
            displayName: $text($claims['name'] ?? null),
            // Not kept from OIDC: Logbook doesn't verify emails (#51).
            email: null,
            locale: $text($claims['locale'] ?? null),
            groups: ExternalAccount::groupList($claims[$config->groupsClaim] ?? []),
        );
    }
}
