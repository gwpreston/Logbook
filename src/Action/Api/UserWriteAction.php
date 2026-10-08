<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiUserWrites;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The key user's own writes (Phase 39.2, spec.md §7.20): station
 * favourites, attention hiding, saved journeys and price alerts. The route
 * names which in its `write` argument.
 *
 * - `PUT`/`DELETE /stations/{station}/favourite`, `POST /attention/{key}/hide`: 204, idempotent.
 * - `POST /journeys`, `POST /fuel-prices/alerts`: 201 (200 when the alert existed and was changed).
 * - `PATCH /journeys/{journey}`, `/fuel-prices/alerts/{alert}`: 200; `DELETE`: 204.
 */
final readonly class UserWriteAction
{
    public function __construct(
        private ApiUserWrites $writes,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $method = $request->getMethod();
        $body = static fn (): array => JsonInput::decode((string) $request->getBody());
        $done = $response->withStatus(204);

        switch ($args['write'] ?? '') {
            case 'favourite':
                $this->writes->favourite($user, (int) ($args['station'] ?? 0), $method === 'PUT');

                return $done;
            case 'hide':
                $this->writes->hide($user, $args['key'] ?? '');

                return $done;
            case 'journey':
                $id = isset($args['journey']) ? (int) $args['journey'] : null;
                if ($method === 'DELETE' && $id !== null) {
                    $this->writes->deleteJourney($user, $id);

                    return $done;
                }
                $journey = $this->writes->saveJourney($user, $body(), $id);

                return $id === null
                    ? $this->responder->json(['entry' => $journey, 'duplicate' => false, 'warnings' => []], 201)
                    : $this->responder->json(['entry' => $journey, 'warnings' => []]);
            case 'alert':
                $id = isset($args['alert']) ? (int) $args['alert'] : null;
                if ($id === null) {
                    $result = $this->writes->setAlert($user, $body());

                    return $this->responder->json(
                        ['entry' => $result['alert'], 'duplicate' => !$result['created'], 'warnings' => []],
                        $result['created'] ? 201 : 200,
                    );
                }
                if ($method === 'DELETE') {
                    $this->writes->removeAlert($user, $id);

                    return $done;
                }

                return $this->responder->json(['entry' => $this->writes->changeAlert($user, $id, $body()), 'warnings' => []]);
        }

        throw new \LogicException('The route names no user write.');
    }
}
