<?php

declare(strict_types=1);

namespace Logbook\Action\Report;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Service\Report\OwnershipOverviewService;
use Logbook\Service\Report\OwnershipService;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /reports/ownership — cost of ownership per vehicle and across the
 * fleet (spec.md §7.7), by currency: summary and vehicle cards on screen,
 * the table in print. The filters (vehicle, include
 * archived) are GET parameters, so the view is a bookmarkable URL.
 */
final readonly class OwnershipReportAction
{
    public function __construct(
        private VehicleService $vehicles,
        private OwnershipService $ownership,
        private OwnershipOverviewService $overview,
        private View $view,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $filter = ReportFilter::fromQuery($request->getQueryParams(), $today);

        return $this->view->render($request, $response, 'reports/ownership.twig', [
            'report' => $this->ownership->report($user, $filter, $today),
            // The screen's cards (spec.md §7.7 *Cost of ownership page*); print keeps the table.
            'overview' => $this->overview->overview($user, $filter, $today),
            'filter' => $filter,
            'all_vehicles' => $this->vehicles->listWith($user, VehicleAbility::ViewCosts, true),
            'filter_query' => self::query($filter),
        ]);
    }

    /**
     * The ownership view's own filters: its period is always the ownership
     * period, so the reports' range is not carried along.
     *
     * @return array<string, string>
     */
    public static function query(ReportFilter $filter): array
    {
        return ($filter->vehicleId !== null ? ['vehicle' => (string) $filter->vehicleId] : [])
            + ($filter->includeArchived ? ['include_archived' => '1'] : []);
    }
}
