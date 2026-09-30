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
 * POST /api/v1/vehicles/{id}/odometer — add an odometer reading (spec.md
 * §7.20): 201 with the reading and any warnings, or 200 with the existing
 * reading and `duplicate: true`.
 */
final readonly class LogReadingAction
{
    public function __construct(
        private ApiWriter $writer,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->writer->logReading(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            JsonInput::decode((string) $request->getBody()),
        );

        return $this->responder->json([
            'entry' => Serializer::odometerReading($result['reading']),
            'duplicate' => $result['duplicate'],
            'warnings' => $result['warnings'],
        ], $result['duplicate'] ? 200 : 201);
    }
}
