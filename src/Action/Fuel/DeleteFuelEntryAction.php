<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/fuel/{entry}/delete — confirm (works without JS),
 * then delete a fill-up and its odometer reading.
 */
final readonly class DeleteFuelEntryAction
{
    public function __construct(
        private VehicleService $vehicles,
        private FuelService $fuel,
        private DisplayFormatter $formatter,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $entry = FuelRoute::entry($this->fuel, $vehicle, $request, $args);
        $currency = $this->vehicles->currencyFor(RequestContext::requireUser($request), $vehicle);
        $description = [
            'date' => $this->formatter->instantDate($entry->data->filledAt),
            'total' => $this->formatter->money($entry->data->totalCost, $currency),
        ];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'fuel.delete_title',
                'body' => 'fuel.delete_body',
                'params' => $description,
                'action' => ['fuel.delete', ['id' => $vehicle->id, 'entry' => $entry->id]],
                'cancel' => ['fuel.index', ['id' => $vehicle->id]],
                'active_tab' => 'fuel',
            ]);
        }

        $this->fuel->delete($vehicle, $entry);
        RequestContext::session($request)->flash('success', 'fuel.deleted', $description);

        return $this->redirect->toRoute('fuel.index', ['id' => (string) $vehicle->id]);
    }
}
