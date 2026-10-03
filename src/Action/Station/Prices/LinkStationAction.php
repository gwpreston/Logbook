<?php

declare(strict_types=1);

namespace Logbook\Action\Station\Prices;

use Logbook\Action\Station\StationRoute;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\LinkRefused;
use Logbook\Service\FuelPrices\StationLinker;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /stations/{station}/link — link (`ref`), unlink (`unlink=1`) or set
 * *Keep my details* (`keep=0|1`) on a station (spec.md §7.34 *Linking
 * stations*): its creator or an admin, while a provider is enabled.
 */
final readonly class LinkStationAction
{
    public function __construct(
        private StationService $stations,
        private StationLinker $linker,
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
        if (!$this->stations->canEdit($user, $station)) {
            throw new HttpForbiddenException($request);
        }
        $input = RequestContext::form($request);
        $session = RequestContext::session($request);
        $ref = is_string($input['ref'] ?? null) ? trim($input['ref']) : '';

        if (($input['unlink'] ?? '') === '1') {
            $this->linker->unlink($station);
            $session->flash('success', 'fuel_prices.link.unlinked');
        } elseif (isset($input['keep'])) {
            $this->linker->keepMyDetails($station, $input['keep'] === '1');
            $session->flash('success', 'fuel_prices.link.keep_saved');
        } elseif ($ref !== '') {
            try {
                $this->linker->link($station, $ref);
                $session->flash('success', 'fuel_prices.link.linked');
            } catch (LinkRefused $e) {
                $session->flash('error', $e->messageKey());
            }
        }

        return $this->redirect->toRoute('stations.show', ['station' => (string) $station->id]);
    }
}
