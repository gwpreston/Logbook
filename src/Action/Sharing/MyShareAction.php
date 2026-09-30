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
 * POST /vehicles/{id}/sharing/me/{action:notify|leave} — a shared user's
 * own choices (spec.md §7.21): whether they get the vehicle's reminders, or
 * leaving the share. The owner has no share, so this is a 404 for them.
 */
final readonly class MyShareAction
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
        $user = RequestContext::requireUser($request);
        if ($this->sharing->shareOf($user, $vehicle) === null) {
            throw new HttpNotFoundException($request);
        }
        $session = RequestContext::session($request);

        if (($args['action'] ?? '') === 'leave') {
            $this->sharing->leave($user, $vehicle);
            $session->flash('success', 'sharing.left', ['name' => $vehicle->name()]);

            return $this->redirect->toRoute('garage');
        }

        $this->sharing->setNotify($user, $vehicle, (RequestContext::form($request)['notify'] ?? '') === '1');
        $session->flash('success', 'sharing.saved');

        return $this->redirect->toRoute('vehicles.sharing', ['id' => (string) $vehicle->id]);
    }
}
