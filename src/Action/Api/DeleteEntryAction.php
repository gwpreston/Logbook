<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiEditor;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/vehicles/{id}/{list}/{entry} — delete an entry through the
 * delete page's service (spec.md §7.20 *Phase 39*): 204.
 */
final readonly class DeleteEntryAction
{
    public function __construct(private ApiEditor $editor)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->editor->delete(
            EditEntryAction::list($args),
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            (int) ($args['entry'] ?? 0),
            EditEntryAction::ifMatch($request),
        );

        return $response->withStatus(204);
    }
}
