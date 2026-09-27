<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id} — vehicle overview: current odometer and fuel figures,
 * details, and the latest fill-ups. Mileage and fuel have their own tabs;
 * maintenance and documents are placeholders until their phases land.
 */
final readonly class ShowVehicleAction
{
    private const int RECENT_FILLS = 3;

    public function __construct(
        private VehicleService $vehicles,
        private OdometerService $odometer,
        private FuelService $fuel,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $fuel = $this->fuel->history($vehicle);
        $kind = Fuel::defaultFor($vehicle->data->fuelType)->kind();

        return $this->view->render($request, $response, 'vehicles/show.twig', [
            'vehicle' => $vehicle,
            'currency' => $this->vehicles->currencyFor(RequestContext::requireUser($request), $vehicle),
            'odometer' => $this->odometer->history($vehicle),
            'fuel' => $fuel,
            'fuel_summary' => $fuel->summary($kind),
            'electric' => $kind === EnergyKind::Electric,
            'recent_fills' => array_slice($fuel->newestFirst(), 0, self::RECENT_FILLS),
        ]);
    }
}
