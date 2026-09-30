<?php

declare(strict_types=1);

namespace Logbook\Action\Trip;

use Logbook\Action\EntryGuard;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/trips/{entry}/delete — confirm, then delete a trip
 * with its attachments.
 */
final readonly class DeleteTripAction
{
    public function __construct(
        private TripService $trips,
        private DisplayFormatter $formatter,
        private View $view,
        private Redirector $redirect,
        private EntryGuard $guard,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $trip = TripRoute::trip($this->trips, $user, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $trip->createdBy);
        $description = [
            'date' => $this->formatter->date($trip->data->travelledOn),
            'journey' => $trip->journey(),
        ];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'trip.delete_title',
                'body' => 'trip.delete_body',
                'params' => $description,
                'action' => ['trips.delete', ['id' => $vehicle->id, 'entry' => $trip->id]],
                'cancel' => ['trips.index', ['id' => $vehicle->id]],
            ]);
        }

        $this->trips->delete($vehicle, $trip);
        RequestContext::session($request)->flash('success', 'trip.deleted', $description);

        return $this->redirect->toRoute('trips.index', ['id' => (string) $vehicle->id]);
    }
}
