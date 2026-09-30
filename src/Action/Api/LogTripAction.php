<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiWriter;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/vehicles/{id}/trips — log a trip (spec.md §7.20, §7.22),
 * optionally from a saved journey (`journey_id`): 201 with the trip and any
 * warnings, or 200 with the existing trip and `duplicate: true` when the
 * key's user logged it already.
 */
final readonly class LogTripAction
{
    public function __construct(
        private ApiWriter $writer,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->writer->logTrip(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            JsonInput::decode((string) $request->getBody()),
        );

        return $this->responder->json([
            'entry' => Serializer::trip($result['trip']),
            'duplicate' => $result['duplicate'],
            'warnings' => $result['warnings'],
        ], $result['duplicate'] ? 200 : 201);
    }
}
