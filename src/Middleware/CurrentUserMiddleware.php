<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Repository\UserRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the signed-in user once per request and exposes it as the `user`
 * request attribute (null when signed out). This is the single auth seam:
 * Actions read the attribute, never the session, so other ways of signing
 * in (multi-user, SSO, proxy headers) only need to change this class.
 */
final readonly class CurrentUserMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'user';

    public function __construct(private UserRepository $users, private VehicleAccess $access)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Access answers remembered by an earlier request (a long-lived container) are not this one's.
        $this->access->forget();
        $session = RequestContext::session($request);
        $userId = $session->userId();
        $user = $userId === null ? null : $this->users->find($userId);

        if ($userId !== null && $user === null) {
            // The account no longer exists: drop the stale sign-in.
            $session->destroy();
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $user));
    }
}
