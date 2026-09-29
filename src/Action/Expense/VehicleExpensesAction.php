<?php

declare(strict_types=1);

namespace Logbook\Action\Expense;

use Logbook\Action\Report\ReportCharts;
use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Attachment\AttachmentService;
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
 * with a link to where it was logged, plus spend per month over the last 12
 * months (fixed, whatever the range). Ad-hoc expenses are managed here.
 */
final readonly class VehicleExpensesAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ReportService $reports,
        private ReportCharts $charts,
        private AttachmentService $attachments,
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
        // The chart always shows the last 12 months, whatever range is picked.
        $twelve = new ReportFilter(ReportPeriod::preset(ReportRange::TwelveMonths, $today), $vehicle->id);
        [$report, $lastTwelve] = $this->reports->compare($user, [$vehicle], [$filter, $twelve]);
        $rows = $report->newestFirst();
        $pagination = Pagination::fromQuery($query, count($rows));

        return $this->view->render($request, $response, 'expenses/index.twig', [
            'vehicle' => $vehicle,
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
            'report' => $report,
            'section' => $report->currencies[0],
            'last_twelve' => $lastTwelve->currencies[0],
            'monthly_chart' => $this->charts->monthly($lastTwelve->currencies[0]),
            'ranges' => ReportRange::presets(),
            'rows' => $pagination->slice($rows),
            'pagination' => $pagination,
            'attachment_counts' => $this->attachments->counts($vehicle),
        ]);
    }
}
