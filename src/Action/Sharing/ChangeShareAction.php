<?php

declare(strict_types=1);

namespace Logbook\Action\Sharing;

use Logbook\Service\Sharing\SharingService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /vehicles/{id}/sharing/{user}/{action:save|remove} — the owner
 * changes or removes one share (spec.md §7.21; the route declares `Own`).
 * A user without a share on this vehicle is a 404.
 */
final readonly class ChangeShareAction
{
    public function __construct(
        private SharingService $sharing,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $userId = (int) ($args['user'] ?? 0);
        $session = RequestContext::session($request);

        if (($args['action'] ?? '') === 'remove') {
            if ($this->sharing->shareOfUserId($vehicle, $userId) === null) {
                throw new HttpNotFoundException($request);
            }
            $this->sharing->remove($vehicle, $userId);
            $session->flash('success', 'sharing.removed');
        } else {
            $share = ShareForm::parse(RequestContext::form($request));
            if (!$this->sharing->update($vehicle, $userId, $share->level, $share->canSeeCosts, $share->notify)) {
                throw new HttpNotFoundException($request);
            }
            $session->flash('success', 'sharing.saved');
        }

        return $this->redirect->toRoute('vehicles.sharing', ['id' => (string) $vehicle->id]);
    }
}
