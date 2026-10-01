<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Proxy;

use Logbook\Service\Auth\External\ExternalAccount;
use Logbook\Service\Auth\External\ExternalPolicy;
use Logbook\Service\Auth\External\ExternalUsers;
use Logbook\Service\Auth\Oidc\OidcOutcome;
use Logbook\Service\Auth\Oidc\OidcResult;
use Logbook\Support\Config\ProxyAuthConfig;
use Logbook\Support\Log\LogThrottle;
use Psr\Log\LoggerInterface;

/**
 * Who a proxy's account is in Logbook (spec.md §7.9 *Finding the user*,
 * *Linking while signed in*): Phase 23.1's rules with the AUTH_PROXY_*
 * policy. Asked on every request that brings a header no session knows
 * yet, so refusals are logged at most once per account per hour.
 */
final readonly class ProxySignIn
{
    public function __construct(
        private ProxyAuthConfig $config,
        private ExternalUsers $users,
        private LogThrottle $throttle,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(ExternalAccount $account): OidcResult
    {
        $result = $this->users->resolve($this->policy(), $account);
        $unlinked = $result->outcome === OidcOutcome::NotLinked;
        if ($unlinked && $this->throttle->allow('proxy-unlinked|' . ProxyRead::keyOf($account))) {
            $this->logger->notice(
                'Header sign-in: "{subject}" is not linked to any user, or not in AUTH_PROXY_ALLOWED_GROUPS.',
                ['subject' => $account->subject],
            );
        }

        return $result;
    }

    /**
     * Whether the signed-in $userId may be offered the link banner.
     */
    public function canLink(int $userId, ExternalAccount $account): bool
    {
        return $this->users->canLink($this->policy(), $userId, $account);
    }

    public function link(int $userId, ExternalAccount $account): OidcResult
    {
        return $this->users->link($this->policy(), $userId, $account);
    }

    private function policy(): ExternalPolicy
    {
        return ExternalPolicy::proxy($this->config);
    }
}
