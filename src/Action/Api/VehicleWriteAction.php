<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiVehicleWrites;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Vehicle writes (Phase 39.2, spec.md §7.20, #284), named by the route's
 * `write` argument:
 *
 * - `POST /vehicles` (`create`): 201 with the vehicle as `GET
 *   /vehicles/{id}` returns it, or 200 with `duplicate: true`;
 * - `PATCH /vehicles/{id}` (`edit`): 200 with the vehicle and its `ETag`;
 * - `POST /vehicles/{id}/archive` and `/restore`: 200 with the vehicle.
 */
final readonly class VehicleWriteAction
{
    public function __construct(
        private ApiVehicleWrites $vehicles,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $write = $args['write'] ?? '';
        if ($write === 'create') {
            $result = $this->vehicles->create($user, JsonInput::decode((string) $request->getBody()));
            $read = $this->vehicles->read($user, $result['vehicle']);

            return $this->responder->json(
                ['entry' => $read['body'], 'duplicate' => $result['duplicate'], 'warnings' => $result['warnings']],
                $result['duplicate'] ? 200 : 201,
            );
        }

        $vehicle = RequestContext::vehicle($request);
        [$updated, $warnings] = match ($write) {
            'edit' => array_values($this->vehicles->update(
                $user,
                $vehicle,
                JsonInput::decode((string) $request->getBody()),
                EditEntryAction::ifMatch($request),
            )),
            'archive' => [$this->vehicles->archive($user, $vehicle, JsonInput::decode((string) $request->getBody())), []],
            'restore' => [$this->vehicles->restore($user, $vehicle), []],
            default => throw new \LogicException('The route names no vehicle write.'),
        };
        $read = $this->vehicles->read($user, $updated);

        return $this->responder->json(['entry' => $read['body'], 'warnings' => $warnings])->withHeader('ETag', $read['tag']);
    }
}
