<?php

declare(strict_types=1);

namespace Logbook\Action\Valuation;

use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/valuations/{entry}/delete — confirm (works without
 * JS), then delete a valuation and its files.
 */
final readonly class DeleteValuationAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ValuationService $valuations,
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
        $valuation = ValuationRoute::valuation($this->valuations, $vehicle, $request, $args);
        $user = RequestContext::requireUser($request);
        $description = [
            'date' => $this->formatter->date($valuation->data->valuedOn),
            'amount' => $this->formatter->money($valuation->data->amount, $this->vehicles->currencyFor($user, $vehicle)),
        ];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'valuation.delete_title',
                'body' => 'valuation.delete_body',
                'params' => $description,
                'action' => ['valuations.delete', ['id' => $vehicle->id, 'entry' => $valuation->id]],
                'cancel' => ['valuations.index', ['id' => $vehicle->id]],
            ]);
        }

        $this->valuations->delete($vehicle, $valuation);
        RequestContext::session($request)->flash('success', 'valuation.deleted', $description);

        return $this->redirect->toRoute('valuations.index', ['id' => (string) $vehicle->id]);
    }
}
