<?php

declare(strict_types=1);

namespace Logbook\Action\Station\Prices;

use Logbook\Action\Station\StationRoute;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\PriceAlertRefused;
use Logbook\Service\FuelPrices\PriceAlerts;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Number\DecimalParser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /stations/{station}/alerts — *Alert me below* for one grade, or
 * remove it (`remove=1`) (spec.md §7.34 *Price alerts*). The price is per
 * litre in the user's volume unit, as typed.
 */
final readonly class PriceAlertAction
{
    public function __construct(
        private StationService $stations,
        private PriceAlerts $alerts,
        private FuelPriceConfig $config,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->config->enabled()) {
            throw new HttpNotFoundException($request);
        }
        $user = RequestContext::requireUser($request);
        $station = StationRoute::active($this->stations, $request, $args);
        $input = RequestContext::form($request);
        $session = RequestContext::session($request);
        $grade = FuelGrade::tryFrom(is_string($input['grade'] ?? null) ? $input['grade'] : '');
        $back = $this->redirect->toRoute('stations.show', ['station' => (string) $station->id]);
        if ($grade === null) {
            $session->flash('error', 'fuel_prices.alert.refused.grade');

            return $back;
        }
        if (($input['remove'] ?? '') === '1') {
            $this->alerts->remove($user, $station, $grade);
            $session->flash('success', 'fuel_prices.alert.removed');

            return $back;
        }

        $typed = is_string($input['below'] ?? null) ? $input['below'] : '';
        $perUnit = DecimalParser::parse(trim($typed), $user->preferences->locale);
        $below = $perUnit === null ? null : $user->preferences->volumeUnit->pricePerLitre($perUnit, 3);
        try {
            if ($below === null) {
                throw new PriceAlertRefused('price');
            }
            $this->alerts->set($user, $station, $grade, $below);
            $session->flash('success', 'fuel_prices.alert.saved');
        } catch (PriceAlertRefused $e) {
            $session->flash('error', $e->messageKey());
        }

        return $back;
    }
}
