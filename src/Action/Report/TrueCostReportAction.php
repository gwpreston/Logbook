<?php

declare(strict_types=1);

namespace Logbook\Action\Report;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Report\TrueCostWording;
use Logbook\Service\Report\VehicleTrueCost;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /reports/true-cost — each vehicle's cost per distance by calendar
 * year, stacked by part, with what changed from one year to the next, and
 * for the fleet one line per vehicle by currency (spec.md §7.35). The
 * filters (vehicle, include archived) are GET parameters, as on the
 * ownership report.
 */
final readonly class TrueCostReportAction
{
    public function __construct(
        private VehicleService $vehicles,
        private TrueCostService $trueCosts,
        private TrueCostWording $wording,
        private TrueCostCharts $charts,
        private View $view,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $filter = ReportFilter::fromQuery($request->getQueryParams(), $today);
        $rows = $this->trueCosts->fleet($user, $filter, $today);

        /** @var array<string, list<VehicleTrueCost>> $byCurrency */
        $byCurrency = [];
        foreach ($rows as $vtc) {
            $byCurrency[$vtc->currency][] = $vtc;
        }
        $fleetCharts = [];
        foreach ($byCurrency as $currency => $vehicles) {
            $chart = $this->charts->fleet($vehicles, $currency);
            if ($chart !== null) {
                $fleetCharts[$currency] = $chart;
            }
        }

        return $this->view->render($request, $response, 'reports/true_cost.twig', [
            'rows' => $rows,
            'charts' => array_map(fn (VehicleTrueCost $vtc) => $this->charts->vehicle($vtc), $rows),
            'chart_labels' => array_map(fn (VehicleTrueCost $vtc): string => $this->charts->label($vtc), $rows),
            'fleet_charts' => $fleetCharts,
            'filter' => $filter,
            'all_vehicles' => $this->vehicles->listWith($user, VehicleAbility::ViewCosts, true),
            'filter_query' => OwnershipReportAction::query($filter),
            'true_cost_wording' => $this->wording,
        ]);
    }
}
