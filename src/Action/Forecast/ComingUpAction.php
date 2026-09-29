<?php

declare(strict_types=1);

namespace Logbook\Action\Forecast;

use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /upcoming — *Coming up* (spec.md §7.18): the next 12 months across
 * every active vehicle, or the one `?vehicle=` picks. Core, like the fleet
 * history; a switched-off module's items simply leave it.
 */
final readonly class ComingUpAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ComingUp $comingUp,
        private ForecastCharts $charts,
        private View $view,
        private ForecastWording $wording,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $scope = ComingUpScope::of($this->vehicles->listFleet($user), $request->getQueryParams());
        $forecast = $this->comingUp->forecast($user, $scope->vehicles);

        return $this->view->render($request, $response, 'forecast/index.twig', [
            'scope' => $scope,
            'vehicles' => $scope->active,
            'selected' => $scope->selected,
            'forecast' => $forecast,
            'charts' => array_map($this->charts->monthly(...), $forecast->totals),
            'forecast_wording' => $this->wording,
        ]);
    }
}
