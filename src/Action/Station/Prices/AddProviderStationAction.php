<?php

declare(strict_types=1);

namespace Logbook\Action\Station\Prices;

use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\LinkRefused;
use Logbook\Service\FuelPrices\StationLinker;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /stations/near/add — *Add station* from a result (spec.md §7.34):
 * a Logbook station with the provider station's details, linked. Any user
 * may, as §7.33's *Add station*.
 */
final readonly class AddProviderStationAction
{
    public function __construct(
        private FuelPriceConfig $config,
        private StationLinker $linker,
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
        $session->flash('success', 'fuel_prices.link.added');

        return $this->redirect->toRoute('stations.show', ['station' => (string) $station->id]);
    }
}
