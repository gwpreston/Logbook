<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Service\Auth\AuthService;
use Logbook\Service\Auth\Oidc\OidcOutcome;
use Logbook\Service\Auth\Proxy\ProxyHeaders;
use Logbook\Service\Auth\Proxy\ProxyNotice;
use Logbook\Service\Auth\Proxy\ProxyRead;
use Logbook\Service\Auth\Proxy\ProxySignIn;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Security\SafeRedirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Header sign-in (spec.md §7.9 *The session follows the header*). On the
 * page route groups only, outside the auth guard; never on the API, the
 * calendar feed, /health or assets, which sit in no such group.
 *
 * - A header for a user no session holds signs them in (new session id,
 *   CSRF rotated), marked as header-based.
 * - A header-based session whose header is gone, refused or now someone
 *   else's ends first.
 * - A password or OIDC session survives a missing header, and a header
 *   for an account nobody has linked (which it may be offered to link).
 *
 * Whenever the session changes, the answer is a redirect: back to the same
 * page for a GET, home for anything else (the post is never applied), so
 * the next request is built for the new user from the start (locale,
 * display preferences, CSRF). Nothing happens while no user exists:
 * first-run setup stays local.
 */
final readonly class ProxyAuthMiddleware implements MiddlewareInterface
{
    /** The ProxyNotice for this request's page, if any. */
    public const string ATTRIBUTE = 'proxy';

    public function __construct(
        private AppSettings $settings,
        private ProxyHeaders $headers,
        private ProxySignIn $signIn,
        private AuthService $auth,
        private Redirector $redirect,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->settings->proxy->isEnabled()) {
            return $handler->handle($request);
        }
        $session = RequestContext::session($request);
        $read = $this->headers->read($request);
        $key = $read->key();

        if ($key === null) {
            if ($session->cameFromProxy()) {
                // The proxy no longer vouches for anyone: the header-based session ends.
                $session->destroy();

                return $this->again($request);
            }

            return $handler->handle($read->fromListedProxy && RequestContext::user($request) === null
                ? $request->withAttribute(self::ATTRIBUTE, ProxyNotice::missing())
                : $request);
        }
        if ($session->cameFromProxy() && $session->proxyAccount() === $key && RequestContext::user($request) !== null) {
            return $handler->handle($request);
        }
        if ($this->auth->setupRequired()) {
            return $handler->handle($request);
        }

        return $this->follow($request, $handler, $read, $key);
    }

    private function follow(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        ProxyRead $read,
        string $key,
    ): ResponseInterface {
        $account = $read->account;
        assert($account !== null);
        $session = RequestContext::session($request);
        $current = RequestContext::user($request);
        $result = $this->signIn->resolve($account);
        $name = $account->username ?? $account->subject;

        if ($result->signsIn() && $result->user !== null) {
            if ($current !== null && $current->id === $result->user->id && !$session->cameFromProxy()) {
                // Already this user by password or OIDC: nothing to switch.
                return $handler->handle($request);
            }
            $session->signIn($result->user->id);
            $session->markProxy($key);
            if ($result->outcome === OidcOutcome::Created) {
                $session->startWelcome($this->here($request));

                return $this->redirect->toRoute('welcome');
            }

            return $this->again($request);
        }

        if ($session->cameFromProxy()) {
            // A header-based session for someone the proxy no longer names.
            $session->destroy();

            return $this->again($request);
        }
        if ($current !== null) {
            // Kept (decided 2026-10-01, #55); offered the link when it can be made.
            $offer = $result->outcome === OidcOutcome::NotLinked && $this->signIn->canLink($current->id, $account);

            return $handler->handle($offer ? $request->withAttribute(self::ATTRIBUTE, ProxyNotice::linkOffer($name)) : $request);
        }

        return $handler->handle($request->withAttribute(
            self::ATTRIBUTE,
            $result->outcome === OidcOutcome::NotLinked ? ProxyNotice::notLinked($name) : ProxyNotice::refused($name),
        ));
    }

    /**
     * After the session changed: the same page again (GET), else home.
     */
    private function again(ServerRequestInterface $request): ResponseInterface
    {
        $here = $this->here($request);

        return $here !== null ? $this->redirect->to($here) : $this->redirect->toRoute('home');
    }

    /**
     * Where a new user goes after the welcome form: this page, if it was
     * reached by GET.
     */
    private function here(ServerRequestInterface $request): ?string
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $uri = $request->getUri();

        return SafeRedirect::localPath(
            $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : ''),
            $this->settings->basePath,
        );
    }
}
