<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiFigureWrites;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Valuations and schedules (Phase 39.2, spec.md §7.20): `POST
 * /vehicles/{id}/valuations` and `/schedules` (201, or 200 with
 * `duplicate: true`), `PATCH` (200 with the entry and its `ETag`) and
 * `DELETE` (204) on `…/{entry}`. The route names which in `list`.
 */
final readonly class FigureWriteAction
{
    public function __construct(
        private ApiFigureWrites $writes,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicle = RequestContext::vehicle($request);
        $valuations = ($args['list'] ?? '') === 'valuations';
        $id = isset($args['entry']) ? (int) $args['entry'] : null;
        $ifMatch = EditEntryAction::ifMatch($request);

        if ($id === null) {
            $body = JsonInput::decode((string) $request->getBody());
            $result = $valuations
                ? $this->writes->createValuation($user, $vehicle, $body)
                : $this->writes->createSchedule($user, $vehicle, $body);

            return $this->responder->json(
                ['entry' => $result['entry']->body, 'duplicate' => $result['duplicate'], 'warnings' => []],
                $result['duplicate'] ? 200 : 201,
            );
        }
        if ($request->getMethod() === 'DELETE') {
            $valuations
                ? $this->writes->deleteValuation($vehicle, $id, $ifMatch)
                : $this->writes->deleteSchedule($vehicle, $id, $ifMatch);

            return $response->withStatus(204);
        }
        $body = JsonInput::decode((string) $request->getBody());
        $entry = $valuations
            ? $this->writes->updateValuation($user, $vehicle, $id, $body, $ifMatch)
            : $this->writes->updateSchedule($user, $vehicle, $id, $body, $ifMatch);

        return $this->responder->json(['entry' => $entry->body, 'warnings' => []])->withHeader('ETag', $entry->tag);
    }
}
