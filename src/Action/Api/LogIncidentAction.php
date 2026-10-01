<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiIncidents;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/vehicles/{id}/incidents (spec.md §7.20, §7.29): through the
 * incident form's parser; a retry finds the one already logged.
 */
final readonly class LogIncidentAction
{
    public function __construct(
        private ApiIncidents $incidents,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->incidents->log(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            JsonInput::decode((string) $request->getBody()),
        );

        return $this->responder->json([
            'entry' => $result['entry'],
            'duplicate' => $result['duplicate'],
            'warnings' => [],
        ], $result['duplicate'] ? 200 : 201);
    }
}
