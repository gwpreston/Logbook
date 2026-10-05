<?php

declare(strict_types=1);

namespace Logbook\Action\Station\Prices;

use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\LinkRefused;
use Logbook\Service\FuelPrices\StationLinker;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /stations/near/add — *Add station* from a result (spec.md §7.34):
 * a Logbook station with the provider station's details, linked. Any user
 * may, as §7.33's *Add station*. From the Fuel stations page, `then`
 * favourites it (`favourite`) or opens the vehicle's fill-up form there
 * (`fuel`, with `vehicle`).
 */
final readonly class AddProviderStationAction
{
    public function __construct(
        private FuelPriceConfig $config,
        private StationLinker $linker,
        private StationService $stations,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->enabled()) {
            throw new HttpNotFoundException($request);
        }
        $user = RequestContext::requireUser($request);
        $input = RequestContext::form($request);
        $ref = is_string($input['ref'] ?? null) ? trim($input['ref']) : '';
        $session = RequestContext::session($request);
        try {
            $station = $this->linker->addFromProvider($user, $ref);
        } catch (LinkRefused $e) {
            $session->flash('error', $e->messageKey());

            return $this->redirect->backOr($request, 'stations.near');
        }
        // From the Fuel stations page (Phase 33.4): added, then favourited or a fill-up there.
        $then = is_string($input['then'] ?? null) ? $input['then'] : '';
        $vehicle = is_string($input['vehicle'] ?? null) && ctype_digit($input['vehicle']) ? $input['vehicle'] : null;
        if ($then === 'fuel' && $vehicle !== null) {
            return $this->redirect->toRoute('fuel.create', ['id' => $vehicle], ['station' => (string) $station->id]);
        }
        if ($then === 'favourite') {
            $this->stations->setFavourite($user, $station, true);
            $session->flash('success', 'stations.favourited');

            return $this->redirect->backOr($request, 'stations.index');
        }
        $session->flash('success', 'fuel_prices.link.added');

        return $this->redirect->toRoute('stations.show', ['station' => (string) $station->id]);
    }
}
