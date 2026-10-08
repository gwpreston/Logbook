<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiTyreWrites;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tyre writes (Phase 39.2, spec.md §7.17, §7.20), named by the route's
 * `write` argument: `POST …/tyres/changes` (201 with the change as `GET
 * …/tyres/changes` lists it), `PATCH` (200, with its `ETag`) and `DELETE`
 * (204) on `…/tyres/changes/{change}`, and `PATCH …/tyres/{tyre}` (200
 * with the tyre as `GET …/tyres` lists it).
 */
final readonly class TyreWriteAction
{
    public function __construct(
        private ApiTyreWrites $tyres,
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
        $ifMatch = EditEntryAction::ifMatch($request);

        if (($args['write'] ?? '') === 'tyre') {
            $body = JsonInput::decode((string) $request->getBody());
            $id = (int) ($args['tyre'] ?? 0);
            $tyre = $this->tyres->updateTyre($user, $vehicle, $id, $body, $ifMatch);

            return $this->responder->json(['entry' => $tyre, 'warnings' => []])
                ->withHeader('ETag', $this->tyres->tyreTag($vehicle, $id));
        }
        if (!isset($args['change'])) {
            $result = $this->tyres->record($user, $vehicle, JsonInput::decode((string) $request->getBody()));

            return $this->responder->json(
                ['entry' => $result['change'], 'duplicate' => false, 'warnings' => $result['warnings']],
                201,
            );
        }
        $id = (int) $args['change'];
        if ($request->getMethod() === 'DELETE') {
            $this->tyres->delete($user, $vehicle, $id, $ifMatch);

            return $response->withStatus(204);
        }
        $result = $this->tyres->update($user, $vehicle, $id, JsonInput::decode((string) $request->getBody()), $ifMatch);

        return $this->responder->json(['entry' => $result['change'], 'warnings' => $result['warnings']])
            ->withHeader('ETag', $result['tag']);
    }
}
