<?php

declare(strict_types=1);

namespace Logbook\Action\Report;

use Logbook\Service\Incident\IncidentReport;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Trip\BusinessMileage;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\Pagination;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /reports — expenses and reports (spec.md §7.7): the fleet or one
 * vehicle over a period, by group, per month and per vehicle. Every filter
 * is a GET parameter, so a report is a bookmarkable URL.
 */
final readonly class ReportAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ReportService $reports,
        private ReportCharts $charts,
        private View $view,
        private ClockInterface $clock,
        private FeatureToggles $features,
        private BusinessMileage $business,
        private IncidentReport $incidents,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $filter = ReportFilter::fromQuery($request->getQueryParams(), $today);
        $report = $this->reports->build($user, $filter);
        $rows = $report->newestFirst();
        $pagination = Pagination::fromQuery($request->getQueryParams(), count($rows));

        return $this->view->render($request, $response, 'reports/index.twig', [
            'report' => $report,
            'filter' => $filter,
            'today' => $today,
            'rows' => $pagination->slice($rows),
            'pagination' => $pagination,
            'ranges' => ReportRange::cases(),
            'cost_groups' => CostGroup::cases(),
            'all_vehicles' => $this->vehicles->listWith($user, VehicleAbility::ViewCosts, true),
            'charts' => array_map($this->charts->monthly(...), $report->currencies),
            'filter_query' => $filter->toQuery(),
            'ownership_query' => OwnershipReportAction::query($filter),
            // Incidents (Phase 27.1, spec.md §7.29): spend itself is unchanged.
            'incident_lines' => $this->incidents->lines($user, $report),
            // Business mileage (Phase 22, spec.md §7.7), with trips on.
            'business' => $this->features->isEnabled(Feature::Trips)
                ? $this->business->build(
                    $user,
                    $report->vehicles,
                    $report->period->from ?? $report->period->to,
                    $report->period->to,
                    $today,
                )
                : null,
        ]);
    }
}
