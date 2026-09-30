<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Middleware\ApiAuthMiddleware;
use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/me — the key's user and preferences, the key's name and
 * scope, and which modules are on (spec.md §7.20).
 */
final readonly class MeAction
{
    public function __construct(
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->json(
            $this->reader->me(RequestContext::requireUser($request), ApiAuthMiddleware::key($request)),
        );
    }
}
