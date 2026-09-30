<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiReader;
use Logbook\Service\Api\ApiWriter;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/vehicles/{id}/fuel — log a fill-up (spec.md §7.20): 201
 * with the entry and any warnings, or 200 with the existing entry and
 * `duplicate: true` when it was logged already.
 */
final readonly class LogFuelAction
{
    public function __construct(
        private ApiWriter $writer,
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicle = RequestContext::vehicle($request);
        $result = $this->writer->logFuel($user, $vehicle, JsonInput::decode((string) $request->getBody()));

        return $this->responder->json([
            'entry' => $this->reader->fuelEntry($user, $vehicle, $result['entry']),
            'duplicate' => $result['duplicate'],
            'warnings' => $result['warnings'],
        ], $result['duplicate'] ? 200 : 201);
    }
}
