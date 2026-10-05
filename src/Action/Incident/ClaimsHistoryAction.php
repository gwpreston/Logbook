<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Incident\Fault;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Incident\ClaimsFilter;
use Logbook\Service\Incident\ClaimsHistory;
use Logbook\Service\Incident\ClaimsStats;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /incidents/history — the claims history (spec.md §7.29): the tiles
 * and the rows as a list on screen, the table when printed (through the
 * browser, with its own header).
 */
final readonly class ClaimsHistoryAction
{
    public function __construct(
        private ClaimsHistory $history,
        private VehicleRepository $vehicles,
        private VehicleAccess $access,
        private View $view,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $filter = ClaimsFilter::fromQuery($request->getQueryParams());
        $report = $this->history->report($user, $filter);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        return $this->view->render($request, $response, 'incidents/history.twig', [
            'report' => $report,
            'stats' => ClaimsStats::of($report->rows, $today),
            'filter' => $filter,
            'filter_query' => $filter->toQuery(),
            'vehicles' => $this->vehicles->listByIds($this->access->visibleVehicleIds($user, VehicleScope::All)),
            'year_choices' => ClaimsFilter::YEARS,
            'faults' => Fault::cases(),
            'today' => $today,
        ]);
    }
}
