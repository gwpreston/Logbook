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
 * POST /api/v1/vehicles/{id}/maintenance — add a service record (spec.md §7.20, Phase 26.3):
 * 201 with it and any warnings, or 200 with the existing one and
 * `duplicate: true`.
 */
final readonly class LogMaintenanceAction
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
        $result = $this->writer->logMaintenance($user, $vehicle, JsonInput::decode((string) $request->getBody()));

        return $this->responder->json([
            'entry' => $this->reader->maintenanceEntry($user, $vehicle, $result['entry']),
            'duplicate' => $result['duplicate'],
            'warnings' => $result['warnings'],
        ], $result['duplicate'] ? 200 : 201);
    }
}
