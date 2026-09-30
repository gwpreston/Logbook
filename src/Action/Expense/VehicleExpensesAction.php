<?php

declare(strict_types=1);

namespace Logbook\Action\Expense;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Action\Report\ReportCharts;
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
 * Without ViewCosts (a share, spec.md §7.21) it lists the ad-hoc expenses
 * only, with the amounts of one's own.
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
        private VehicleAccess $access,
        private ExpenseService $expenses,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        if (!$this->access->can($user, VehicleAbility::ViewCosts, $vehicle)) {
            return $this->withoutCosts($request, $response, $vehicle);
        }
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

    private function withoutCosts(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $rows = array_map(
            static fn (ExpenseEntry $entry): CostItem => CostItem::fromExpense($entry, $vehicle, $currency),
            array_reverse($this->expenses->entries($vehicle)),
        );
        $pagination = Pagination::fromQuery($request->getQueryParams(), count($rows));

        return $this->view->render($request, $response, 'expenses/without_costs.twig', [
            'vehicle' => $vehicle,
            'rows' => $pagination->slice($rows),
            'pagination' => $pagination,
            'attachment_counts' => $this->attachments->counts($vehicle),
        ]);
    }
}
