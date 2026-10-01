<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Service\Auth\Oidc\OidcOutcome;
use Logbook\Service\Auth\Proxy\ProxyHeaders;
use Logbook\Service\Auth\Proxy\ProxySignIn;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /auth/proxy/link — *Link your proxy account* (spec.md §7.9
 * *Linking while signed in*): the proxy account is read again from this
 * request's headers, with every check, never from the form or the session.
 */
final readonly class ProxyLinkAction
{
    public function __construct(
        private AppSettings $settings,
        private ProxyHeaders $headers,
        private ProxySignIn $signIn,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->settings->proxy->isEnabled()) {
            throw new HttpNotFoundException($request);
        }
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);
        $account = $this->headers->read($request)->account;
        if ($account === null) {
            $session->flash('error', 'proxy.link_missing');

            return $this->redirect->backOr($request, 'home');
        }

        $result = $this->signIn->link($user->id, $account);
        [$type, $key] = match ($result->outcome) {
            OidcOutcome::Linked => ['success', 'proxy.linked'],
            OidcOutcome::LinkTaken => ['error', 'proxy.link_taken'],
            OidcOutcome::AlreadyLinked => ['error', 'proxy.already_linked'],
            OidcOutcome::NotLinked => ['error', 'proxy.link_not_allowed'],
            default => ['error', 'proxy.refused'],
        };
        $session->flash($type, $key, ['name' => $account->username ?? $account->subject]);

        return $this->redirect->backOr($request, 'home');
    }
}
