<?php

declare(strict_types=1);

namespace Logbook\Action\Tyre;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Tyre\TyreSync;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/tyres/{tyre}/delete — confirm (works without JS),
 * then delete a tyre with its lines; a change left with no lines goes too.
 */
final readonly class DeleteTyreAction
{
    public function __construct(
        private VehicleService $vehicles,
        private TyreService $tyres,
        private TyreChangeService $changes,
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
        $tyre = TyreRoute::tyre($this->tyres, $vehicle, $request, $args);

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'tyre.delete_title',
                'body' => 'tyre.delete_body',
                'params' => ['tyre' => TyreSync::label($tyre)],
                'action' => ['tyres.delete', ['id' => $vehicle->id, 'tyre' => $tyre->id]],
                'cancel' => ['tyres.index', ['id' => $vehicle->id]],
            ]);
        }

        $this->changes->deleteTyre($vehicle, $tyre);
        RequestContext::session($request)->flash('success', 'tyre.deleted');

        return $this->redirect->toRoute('tyres.index', ['id' => (string) $vehicle->id]);
    }
}
