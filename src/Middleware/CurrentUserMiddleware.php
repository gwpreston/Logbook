<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Repository\UserRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Sharing\AuthorLabels;
use Logbook\Service\User\UserDirectory;
use Logbook\Service\Vehicle\PlateStyleResolver;
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

    /**
     * Names the signed-in user on their own responses (spec.md §7.15, K40): the
     * service worker remembers it, and keeps and serves a fill-up page,
     * and the page sends a queued fill-up, only for that user.
     */
    public const string USER_HEADER = 'X-Logbook-User';

    public function __construct(
        private UserRepository $users,
        private VehicleAccess $access,
        private AccessContext $context,
        private UserDirectory $directory,
        private AuthorLabels $authors,
        private PlateStyleResolver $plates,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Access answers remembered by an earlier request (a long-lived container) are not this one's.
        $this->access->forget();
        $this->directory->forget();
        $this->authors->forget();
        $this->plates->forget();
        $session = RequestContext::session($request);
        $userId = $session->userId();
        $user = $userId === null ? null : $this->users->find($userId);

        if ($userId !== null && ($user === null || !$user->isActive())) {
            // The account no longer exists or was disabled (Phase 19): drop the stale sign-in.
            $session->destroy();
            $user = null;
        }

        $this->context->apply($user);

        $response = $handler->handle($request->withAttribute(self::ATTRIBUTE, $user));

        // Only on pages (not the API or MCP, whose user is the key's, K167),
        // not on what shared caches may keep (the manifest, `public`; the
        // service worker and offline page, `no-cache`), nor once the request
        // has signed the user out.
        $page = str_starts_with($response->getHeaderLine('Content-Type'), 'text/html');
        $cache = $response->getHeaderLine('Cache-Control');
        $shared = str_contains($cache, 'public') || $cache === 'no-cache';

        return $user === null || !$page || $shared || $session->userId() !== $user->id
            ? $response
            : $response->withHeader(self::USER_HEADER, (string) $user->id);
    }
}
