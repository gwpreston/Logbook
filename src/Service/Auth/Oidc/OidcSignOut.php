<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

use Logbook\Support\Config\OidcConfig;
use Logbook\Support\Http\AbsoluteUrl;
use Psr\Log\LoggerInterface;

/**
 * RP-initiated logout (spec.md §7.9 *Sign-out*): only with OIDC_LOGOUT, an
 * ID token kept from the sign-in, and an `end_session_endpoint`. Otherwise
 * sign-out stays local.
 */
final readonly class OidcSignOut
{
    public function __construct(
        private OidcConfig $config,
        private Discovery $discovery,
        private OidcClient $client,
        private AbsoluteUrl $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function url(?string $idToken): ?string
    {
        if (!$this->config->isConfigured() || !$this->config->logout || $idToken === null) {
            return null;
        }
        try {
            $metadata = $this->discovery->metadata();
        } catch (OidcFailure $e) {
            $this->logger->warning('Signed out locally only: {reason}', ['reason' => $e->getMessage()]);

            return null;
        }

        return $this->client->logoutUrl($metadata, $idToken, $this->postLogoutRedirect());
    }

    /**
     * The sign-in page, to register at the provider as the post-logout redirect.
     */
    public function postLogoutRedirect(): string
    {
        return $this->urls->route('login');
    }
}
