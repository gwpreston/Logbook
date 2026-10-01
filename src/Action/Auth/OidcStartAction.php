<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Service\Auth\Oidc\OidcPurpose;
use Logbook\Service\Auth\Oidc\OidcSignIn;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Security\SafeRedirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /auth/oidc/start?next=… — *Sign in with {name}* (spec.md §7.9 *Flow*):
 * remember the flow in the session and go to the provider. Without SSO
 * configured this is a 404; when the provider can't be reached, back to
 * sign-in with a message.
 */
final readonly class OidcStartAction
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
        $nextParam = $request->getQueryParams()['next'] ?? null;
        $next = SafeRedirect::localPath(is_string($nextParam) ? $nextParam : null, $this->settings->basePath);
        if (RequestContext::user($request) !== null) {
            return $next !== null ? $this->redirect->to($next) : $this->redirect->toRoute('home');
        }

        $session = RequestContext::session($request);
        $url = $this->oidc->begin($session, OidcPurpose::SignIn, $next);
        if ($url === null) {
            $session->flash('error', $this->settings->localLogin ? 'sso.unavailable_password' : 'sso.unavailable', [
                'name' => $this->settings->oidc->providerName,
            ]);

            return $this->redirect->toRoute('login', [], $next !== null ? ['next' => $next] : []);
        }

        return $this->redirect->external($url);
    }
}
