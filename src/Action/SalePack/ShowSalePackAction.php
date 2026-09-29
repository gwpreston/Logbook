<?php

declare(strict_types=1);

namespace Logbook\Action\SalePack;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Service\SalePack\SalePackBuilder;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/sale-pack — *Prepare for sale* (spec.md §7.19): a
 * buyer's view of the vehicle for printing or the browser's *Save as PDF*,
 * with the paperwork ZIP beside it. The options are a plain GET form; the
 * pack holds no prices paid, fuel, expenses, valuations or ownership costs
 * whatever they say.
 */
final readonly class ShowSalePackAction
{
    public function __construct(
        private VehicleService $vehicles,
        private SalePackRequest $options,
        private SalePackBuilder $builder,
        private SalePackChart $chart,
        private View $view,
        private ForecastWording $wording,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $user = RequestContext::requireUser($request);
        $options = $this->options->options($request);
        $pack = $this->builder->build($user, $vehicle, $options);

        return $this->view->render($request, $response, 'sale_pack/show.twig', [
            'vehicle' => $vehicle,
            'pack' => $pack,
            'chart' => $this->chart->build($pack->mileage, $user->preferences),
            // What the links carry: the files the seller unticked, never a keep list.
            'query' => $options->excluding($pack->paperwork->excludedIds())->query(),
            'forecast_wording' => $this->wording,
        ]);
    }
}
