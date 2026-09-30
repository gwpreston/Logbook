<?php

declare(strict_types=1);

namespace Logbook\Action\Trip;

use Logbook\Domain\Trip\TaxYear;
use Logbook\Service\Trip\BusinessMileage;
use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Trip\TripSettingsStore;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /trips/claim — the signed-in user's mileage claim (spec.md §7.23) for
 * a tax year (the current one by default) or a custom range, and some or
 * all vehicles, as a plain GET form. Prints through the browser.
 */
final readonly class ClaimReportAction
{
    /** Tax years offered in the filter, counting the current one. */
    private const int YEARS = 7;

    public function __construct(
        private ClaimReportService $claims,
        private BusinessMileage $business,
        private TripSettingsStore $settings,
        private View $view,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $start = $this->settings->for($user)->taxYearStart;
        $filter = ClaimFilter::fromQuery($request->getQueryParams(), $start, $today);
        $report = $this->claims->build($user, $filter);

        $years = [];
        $year = TaxYear::containing($today, $start);
        for ($i = 0; $i < self::YEARS; $i++) {
            $years[] = $year;
            $year = $year->previous();
        }

        return $this->view->render($request, $response, 'trips/claim.twig', [
            'report' => $report,
            'filter' => $filter,
            'years' => $years,
            'today' => $today,
            'business' => $this->business->build($user, $report->included, $filter->from, $filter->to, $today),
            'filter_query' => $filter->toQuery(),
        ]);
    }
}
