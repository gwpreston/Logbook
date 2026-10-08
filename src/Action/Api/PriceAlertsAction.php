<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\FuelPrices\PriceAlert;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\PriceAlerts;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/fuel-prices/alerts — the key user's price alerts (spec.md
 * §7.34, §7.20 *Phase 39*): the station, the grade and the threshold per
 * litre in the provider's currency, and whether it is armed. 404 while no
 * provider is enabled.
 */
final readonly class PriceAlertsAction
{
    public function __construct(
        private FuelPriceConfig $config,
        private PriceAlerts $alerts,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $provider = $this->config->provider() ?? throw ApiProblem::notFound('Fuel prices are off on this install.');

        return $this->responder->json(['items' => array_map(
            static fn (PriceAlert $alert): array => Serializer::priceAlert($alert, $provider->currency()),
            $this->alerts->forUser(RequestContext::requireUser($request)),
        )]);
    }
}
