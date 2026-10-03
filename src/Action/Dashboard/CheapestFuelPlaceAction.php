<?php

declare(strict_types=1);

namespace Logbook\Action\Dashboard;

use Logbook\Service\FuelPrices\CheapestFuelWidgets;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /dashboard/cheapest-fuel — the *Cheapest fuel* widget's place
 * (spec.md §7.34 *Dashboard widget*), one of the user's own.
 */
final readonly class CheapestFuelPlaceAction
{
    public function __construct(
        private FuelPriceConfig $config,
        private CheapestFuelWidgets $widgets,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->enabled()) {
            throw new HttpNotFoundException($request);
        }
        $user = RequestContext::requireUser($request);
        $place = RequestContext::form($request)['place'] ?? '';
        if (is_string($place) && ctype_digit($place)) {
            $this->widgets->choosePlace($user, (int) $place);
        }

        return $this->redirect->backOr($request, 'home');
    }
}
