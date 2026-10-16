<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiModifications;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/vehicles/{id}/modifications (spec.md §7.20 *Parts and modifications*): through the
 * modification form's parser, with the parts lists in the same transaction; a retry finds the
 * one already logged.
 */
final readonly class LogModificationAction
{
    public function __construct(
        private ApiModifications $modifications,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->modifications->log(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            JsonInput::decode((string) $request->getBody()),
        );

        return $this->responder->json($result, $result['duplicate'] ? 200 : 201);
    }
}
