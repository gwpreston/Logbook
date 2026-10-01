<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Auth\Oidc\OidcPurpose;
use Logbook\Service\Auth\Oidc\OidcSignIn;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/sso/link — *Link {name} account* (spec.md §7.9 *Linking*):
 * run the flow for the signed-in user; the callback stores the identity.
 */
final readonly class OidcLinkAction
{
    public function __construct(
        private OidcSignIn $oidc,
        private Redirector $redirect,
        private AppSettings $settings,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->oidc->isConfigured()) {
            throw new HttpNotFoundException($request);
        }
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);
        $url = $this->oidc->begin($session, OidcPurpose::Link, null, $user->id);
        if ($url === null) {
            $session->flash('error', 'sso.link_failed', ['name' => $this->settings->oidc->providerName]);

            return $this->redirect->toRoute('settings');
        }

        return $this->redirect->external($url);
    }
}
