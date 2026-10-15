<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Api\ApiModifications;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/vehicles/{id}/modifications/{entry}/{revert|undo-revert} (spec.md §7.20 *Parts
 * and modifications*): *Revert* (`Log`) and *Undo revert* (`EntryAccess`, #430), each answering
 * the modification as its read returns it, with its new `ETag`. One that changes nothing says
 * `unchanged: true`. `If-Match` is honoured when sent.
 */
final readonly class ModificationWriteAction
{
    public function __construct(
        private ApiModifications $modifications,
        private EntryAccess $access,
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
        $state = $this->modifications->state($vehicle, (int) ($args['entry'] ?? 0));
        $this->modifications->precondition(EditEntryAction::ifMatch($request), $state);

        if (($args['write'] ?? '') === 'undo-revert') {
            if (!$this->access->canChange($user, $vehicle, $state->createdBy)) {
                throw new ApiProblem(403, 'forbidden', 'The key\'s user may not change this entry: it is someone else\'s.');
            }
            $changed = $this->modifications->undoRevert($vehicle, $state);
        } else {
            $changed = $this->modifications->revert($user, $vehicle, $state, JsonInput::decode((string) $request->getBody()));
        }

        $saved = $this->modifications->state($vehicle, $state->modification->id);

        return $this->responder->json([
            'entry' => $this->modifications->serialize($user, $vehicle, $saved),
            'unchanged' => !$changed,
        ])->withHeader('ETag', $this->modifications->tag($saved));
    }
}
