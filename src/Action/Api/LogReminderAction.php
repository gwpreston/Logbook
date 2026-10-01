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
 * POST /api/v1/vehicles/{id}/reminders — add a manual reminder (spec.md §7.20, Phase 26.3):
 * 201 with it and any warnings, or 200 with the existing one and
 * `duplicate: true`.
 */
final readonly class LogReminderAction
{
    public function __construct(
        private ApiWriter $writer,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicle = RequestContext::vehicle($request);
        $result = $this->writer->logReminder($user, $vehicle, JsonInput::decode((string) $request->getBody()));

        return $this->responder->json([
            'entry' => Serializer::reminder($result['entry'], $result['today']),
            'duplicate' => $result['duplicate'],
            'warnings' => $result['warnings'],
        ], $result['duplicate'] ? 200 : 201);
    }
}
