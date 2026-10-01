<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Service\Auth\Oidc\OidcSignOut;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /logout — end the session (POST-only, CSRF-protected, so a link on
 * another site cannot sign you out). With OIDC_LOGOUT and a session from
 * single sign-on, the browser then signs out at the provider too, which
 * sends it back to sign-in (spec.md §7.9 *Sign-out*).
 */
final readonly class LogoutAction
{
    public function __construct(
        private Redirector $redirect,
        private OidcSignOut $signOut,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = RequestContext::session($request);
        // Read before the session is gone.
        $providerLogout = $this->signOut->url($session->cameFromSingleSignOn() ? $session->singleSignOnIdToken() : null);
        $session->destroy();
        $session->flash('success', 'auth.signed_out');

        return $providerLogout !== null ? $this->redirect->external($providerLogout) : $this->redirect->toRoute('login');
    }
}
