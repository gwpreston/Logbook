<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiParts;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/vehicles/{id}/parts/{entry}/{fit|remove} (spec.md §7.20 *Parts and
 * modifications*): *Fit* / *Fit again* and *Remove* without a record (`Log`), answering the part
 * as its read returns it, with its new `ETag`. A part that is not stored (to fit) or not fitted
 * (to remove) is a 422 naming the part. `If-Match` is honoured when sent.
 */
final readonly class PartWriteAction
{
    public function __construct(
        private ApiParts $parts,
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
        $state = $this->parts->state($vehicle, (int) ($args['entry'] ?? 0));
        $this->parts->precondition(EditEntryAction::ifMatch($request), $state);

        $remove = ($args['write'] ?? '') === 'remove';
        $this->parts->move($user, $vehicle, $state, JsonInput::decode((string) $request->getBody()), $remove);

        $saved = $this->parts->state($vehicle, $state->part->id);
        return $this->responder->json(['entry' => $this->parts->serialize($user, $vehicle, $saved)])
            ->withHeader('ETag', $this->parts->tag($saved));
    }
}
