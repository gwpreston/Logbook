<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiEntries;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/vehicles/{id}/{list}/{entry} — one entry exactly as its list
 * returns it, with an `ETag` (spec.md §7.20 *Phase 39*). The route names
 * the list in its `list` argument; the list's ability and module apply.
 */
final readonly class ShowEntryAction
{
    public function __construct(
        private ApiEntries $entries,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $list = $args['list'] ?? '';
        if (!in_array($list, ApiEntries::LISTS, true)) {
            throw new \LogicException(sprintf('The route names no entry list ("%s").', $list));
        }
        $entry = $this->entries->read(
            $list,
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            (int) ($args['entry'] ?? 0),
        );

        return $this->responder->json($entry->body)->withHeader('ETag', $entry->tag);
    }
}
