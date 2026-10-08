<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiFinanceWrites;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Finance writes (Phase 39.2, spec.md §7.20, #287), named by the route's
 * `write` argument. Each answers with the agreement as `GET
 * …/finance/agreements` lists it (201 for a new agreement, event or
 * quote; 200 for an edit or *End*, with the agreement's `ETag` on an
 * edit), or 204 for a delete.
 */
final readonly class FinanceWriteAction
{
    public function __construct(
        private ApiFinanceWrites $finance,
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
        $id = (int) ($args['agreement'] ?? 0);
        $body = static fn (): array => JsonInput::decode((string) $request->getBody());
        $entry = static fn (array $agreement): array => ['entry' => $agreement, 'warnings' => []];

        switch ($args['write'] ?? '') {
            case 'agreement':
                if ($request->getMethod() === 'POST') {
                    return $this->responder->json($entry($this->finance->create($user, $vehicle, $body())), 201);
                }
                $edited = $this->finance->update($user, $vehicle, $id, $body(), EditEntryAction::ifMatch($request));

                return $this->responder->json($entry($edited['body']))->withHeader('ETag', $edited['tag']);
            case 'payment':
                if ($request->getMethod() === 'DELETE') {
                    $this->finance->deletePayment($user, $vehicle, $id, (int) ($args['event'] ?? 0));

                    return $response->withStatus(204);
                }

                return $this->responder->json($entry($this->finance->payment($user, $vehicle, $id, $body())), 201);
            case 'quote':
                if ($request->getMethod() === 'DELETE') {
                    $this->finance->deleteQuote($user, $vehicle, $id, (int) ($args['quote'] ?? 0));

                    return $response->withStatus(204);
                }

                return $this->responder->json($entry($this->finance->quote($user, $vehicle, $id, $body())), 201);
            case 'end':
                return $this->responder->json($entry($this->finance->end($user, $vehicle, $id, $body())));
        }

        throw new \LogicException('The route names no finance write.');
    }
}
