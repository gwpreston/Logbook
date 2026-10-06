<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Service\Auth\AuthService;
use Logbook\Service\Demo\DemoMode;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Protects the signed-in part of the app (a route group). Signed-out
 * visitors go to first-run setup while no account exists, otherwise to
 * sign-in, which brings them back to the page they asked for.
 */
final readonly class AuthGuardMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthService $auth,
        private Redirector $redirect,
        private DemoMode $demo,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (RequestContext::user($request) !== null) {
            $response = $handler->handle($request);

            // Personal pages must never be stored by shared caches or reused after sign-out.
            return $response->hasHeader('Cache-Control')
                ? $response
                : $response->withHeader('Cache-Control', 'private, no-store');
        }

        if ($this->auth->setupRequired()) {
            return $this->redirect->toRoute('setup');
        }

        $query = [];
        // A session cookie the database no longer knows, in a demo: its reset ended the session (spec.md §7.36).
        if ($this->demo->isActive() && isset($request->getCookieParams()[SessionMiddleware::COOKIE])) {
            $query['demo'] = 'reset';
        }
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $uri = $request->getUri();
            $query['next'] = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
        }

        return $this->redirect->toRoute('login', [], $query);
    }
}
