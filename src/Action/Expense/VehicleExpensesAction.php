<?php

declare(strict_types=1);

namespace Logbook\Action\Expense;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
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
 * GET /vehicles/{id}/expenses — the expenses tab: the vehicle's spend over a
 * period (`?range=`, like reports) by group, and every cost newest first
 * with a link to where it was logged. Ad-hoc expenses are managed here.
 */
final readonly class VehicleExpensesAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ReportService $reports,
        private View $view,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $query = $request->getQueryParams();

        $filter = new ReportFilter(ReportPeriod::fromQuery($query, $today), $vehicle->id);
        $report = $this->reports->forVehicles($user, $filter, [$vehicle]);
        $rows = $report->newestFirst();
        $pagination = Pagination::fromQuery($query, count($rows));

        return $this->view->render($request, $response, 'expenses/index.twig', [
            'vehicle' => $vehicle,
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
            'report' => $report,
            'section' => $report->currencies[0],
            'ranges' => ReportRange::presets(),
            'rows' => $pagination->slice($rows),
            'pagination' => $pagination,
        ]);
    }
}
