<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Middleware\CurrentUserMiddleware;
use Logbook\Service\Auth\Oidc\OidcSignOut;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /logout — end the session (POST-only, CSRF-protected, so a link on
 * another site cannot sign you out). With OIDC_LOGOUT and a session from
 * single sign-on, the browser then signs out at the provider too, which
 * sends it back to sign-in. A session from a proxy's header goes on to
 * AUTH_PROXY_LOGOUT_URL, or without one gets a page explaining that the
 * proxy signs straight back in (spec.md §7.9 *Sign-out*).
 *
 * Nothing offline is cleared here (spec.md §7.15, K40, #574): fill-ups
 * queued in the browser stay, to be sent when their user next signs in,
 * and the signed-out page the browser lands on makes its service worker
 * forget the user, so no cached fill-up page is shown until someone
 * signs in (#580).
 */
final readonly class LogoutAction
{
    public function __construct(
        private Redirector $redirect,
        private OidcSignOut $signOut,
        private AppSettings $settings,
        private View $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = RequestContext::session($request);
        // Read before the session is gone.
        $fromProxy = $session->cameFromProxy();
        $idToken = $session->cameFromSingleSignOn() ? $session->singleSignOnIdToken() : null;
        $providerLogout = $fromProxy ? null : $this->signOut->url($idToken);
        $session->destroy();

        if ($fromProxy) {
            $logoutUrl = $this->settings->proxy->logoutUrl;
            if ($logoutUrl !== null) {
                return $this->redirect->external($logoutUrl);
            }

            return $this->view->render(
                $request->withAttribute(CurrentUserMiddleware::ATTRIBUTE, null),
                $response->withHeader('Cache-Control', 'no-store'),
                'auth/proxy_signed_out.twig',
            );
        }
        $session->flash('success', 'auth.signed_out');

        return $providerLogout !== null ? $this->redirect->external($providerLogout) : $this->redirect->toRoute('login');
    }
}
