<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/tyres — every tyre with its status, position,
 * tread and what is due (spec.md §7.20). Not paged.
 */
final readonly class ListTyresAction
{
    public function __construct(
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->json([
            'items' => $this->reader->tyres(RequestContext::requireUser($request), RequestContext::vehicle($request)),
        ]);
    }
}
