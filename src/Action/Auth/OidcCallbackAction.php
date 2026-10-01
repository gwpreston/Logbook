<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Service\Auth\Oidc\OidcOutcome;
use Logbook\Service\Auth\Oidc\OidcPurpose;
use Logbook\Service\Auth\Oidc\OidcSignIn;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /auth/oidc/callback — the provider sends the browser back here
 * (spec.md §7.9). A sign-in ends exactly as a password sign-in does (new
 * session id, CSRF rotated, back to the page asked for); a link ends on
 * Settings. Every failure shows a generic message; the reason is logged.
 */
final readonly class OidcCallbackAction
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
        $session = RequestContext::session($request);
        $query = $request->getQueryParams();
        $purpose = $this->oidc->purposeOf($session, $query);
        $result = $this->oidc->complete($session, $query);
        $name = ['name' => $this->settings->oidc->providerName];

        if ($purpose === OidcPurpose::Link && RequestContext::user($request) !== null) {
            [$type, $key] = match ($result->outcome) {
                OidcOutcome::Linked => ['success', 'sso.linked'],
                OidcOutcome::LinkTaken => ['error', 'sso.link_taken'],
                OidcOutcome::AlreadyLinked => ['error', 'sso.already_linked'],
                OidcOutcome::NotLinked => ['error', 'sso.link_not_allowed'],
                default => ['error', 'sso.link_failed'],
            };
            $session->flash($type, $key, $name);

            return $this->redirect->toRoute('settings');
        }

        if (!$result->signsIn() || $result->user === null) {
            $key = match (true) {
                $result->outcome === OidcOutcome::NotLinked => 'sso.not_linked',
                $this->settings->localLogin => 'sso.failed_password',
                default => 'sso.failed',
            };
            $session->flash('error', $key, $name);

            return $this->redirect->toRoute('login');
        }

        $session->signIn($result->user->id);
        $session->markSingleSignOn($this->settings->oidc->logout ? $result->idToken : null);
        if ($result->outcome === OidcOutcome::Created) {
            $session->startWelcome($result->next);

            return $this->redirect->toRoute('welcome');
        }

        return $result->next !== null ? $this->redirect->to($result->next) : $this->redirect->toRoute('home');
    }
}
