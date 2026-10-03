<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\FuelPrices\ListedPrices;
use Logbook\Service\FuelPrices\PriceAlerts;
use Logbook\Service\FuelPrices\StationLinker;
use Logbook\Service\Station\PlaceService;
use Logbook\Service\Station\StationService;
use Logbook\Service\Station\StationStats;
use Logbook\Service\Station\StationVisit;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /stations/{station} — one station (spec.md §7.33 *Station page*): its
 * details, what the user paid there per grade with the price history, and
 * their fill-ups there, newest first. A merged station's page redirects to
 * the station it became. While a price provider is enabled (Phase 30.2,
 * §7.34): *Listed now* beside what the user paid in 12 months, the listed
 * series on the chart, the link (or *Is this the same station?* for its
 * creator or an admin) and, on a favourite, *Alert me below*.
 */
final readonly class ShowStationAction
{
    public function __construct(
        private StationService $stations,
        private PlaceService $places,
        private VehicleRepository $vehicles,
        private StationChart $chart,
        private ListedPrices $listed,
        private StationLinker $linker,
        private PriceAlerts $alerts,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $station = StationRoute::station($this->stations, $request, $args);
        if ($station->isMerged()) {
            $into = $this->stations->resolve($station->id);
            if ($into !== null) {
                return $this->redirect->toRoute('stations.show', ['station' => (string) $into->id]);
            }
        }

        $visits = $this->stations->visits($user, [$station->id]);
        $summary = StationStats::summarise($visits)[$station->id] ?? null;
        $vehicleIds = array_values(array_unique(array_map(
            static fn (StationVisit $visit): int => $visit->entry->vehicleId,
            $visits,
        )));
        $vehicles = [];
        foreach ($this->vehicles->listByIds($vehicleIds) as $vehicle) {
            $vehicles[$vehicle->id] = $vehicle;
        }
        $fills = array_reverse(array_values(array_filter(
            $visits,
            static fn (StationVisit $visit): bool => ($vehicles[$visit->entry->vehicleId] ?? null) instanceof Vehicle,
        )));

        $canEdit = $this->stations->canEdit($user, $station);
        $prices = $this->listed->forStation($station);
        $provider = $this->listed->provider();
        $daily = $prices === null ? [] : $this->listed->daily($station, $user->preferences->timeZone());
        $lastYear = $this->stations->summaries($user, [$station->id], $this->stations->yearAgo())[$station->id] ?? null;

        return $this->view->render($request, $response, 'stations/show.twig', [
            'station' => $station,
            'favourite' => $this->stations->isFavourite($user, $station),
            'can_edit' => $canEdit,
            'summary' => $summary,
            'charts' => $this->chart->build($summary, $user->preferences, $daily, $prices?->provider->currency()),
            // Phase 30.2 (spec.md §7.34).
            'provider' => $provider,
            'prices' => $prices,
            'paid_last_year' => $lastYear,
            'candidates' => $provider !== null && $canEdit && $station->link === null
                ? $this->linker->candidates($station)
                : [],
            'can_alert' => $prices !== null && $this->alerts->canAlert($user, $station),
            'alerts' => $prices === null ? [] : $this->alerts->forStation($user, $station),
            'fills' => $fills,
            'vehicles' => $vehicles,
            'distances' => PlaceService::distances($this->places->list($user), $station),
        ]);
    }
}
