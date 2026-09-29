<?php

declare(strict_types=1);

namespace Logbook\Action\Tyre;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/tyres/sets/{set}/delete — confirm, then delete a
 * set that no longer holds a fitted or stored tyre.
 */
final readonly class DeleteTyreSetAction
{
    public function __construct(
        private VehicleService $vehicles,
        private TyreService $tyres,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $set = TyreRoute::set($this->tyres, $vehicle, $request, $args);
        $session = RequestContext::session($request);

        if ($request->getMethod() !== 'POST') {
            if ($this->tyres->isInUse($vehicle, $set)) {
                $session->flash('error', 'tyre.error.set_in_use', ['set' => $set->data->name]);

                return $this->redirect->toRoute('tyres.sets.edit', ['id' => (string) $vehicle->id, 'set' => (string) $set->id]);
            }

            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'tyre.set.delete_title',
                'body' => 'tyre.set.delete_body',
                'params' => ['set' => $set->data->name],
                'action' => ['tyres.sets.delete', ['id' => $vehicle->id, 'set' => $set->id]],
                'cancel' => ['tyres.index', ['id' => $vehicle->id]],
            ]);
        }

        try {
            $this->tyres->deleteSet($vehicle, $set);
            $session->flash('success', 'tyre.set.deleted');
        } catch (TyreChangeRefused) {
            $session->flash('error', 'tyre.error.set_in_use', ['set' => $set->data->name]);
        }

        return $this->redirect->toRoute('tyres.index', ['id' => (string) $vehicle->id]);
    }
}
