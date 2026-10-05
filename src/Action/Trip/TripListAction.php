<?php

declare(strict_types=1);

namespace Logbook\Action\Trip;

use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Trip\ClaimTotals;
use Logbook\Service\Trip\MileageSplit;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\Pagination;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/trips — the Trips tab (spec.md §7.22): this tax year's
 * business and private distance, the viewer's claim value and the count of
 * the trips they may see, the *Business and private* card, then those
 * trips, newest first.
 */
final readonly class TripListAction
{
    public function __construct(
        private TripService $trips,
        private MileageSplit $split,
        private ClaimReportService $claims,
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
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        $trips = $this->trips->visible($user, $vehicle);
        $pagination = Pagination::fromQuery($request->getQueryParams(), count($trips));
        $claim = $this->claims->thisYear($user, $today, [$vehicle->id]);
        $year = $claim->filter->taxYear;
        assert($year !== null);
        $until = min($today, $year->lastDay());

        return $this->view->render($request, $response, 'trips/index.twig', [
            'vehicle' => $vehicle,
            'trips' => $pagination->slice($trips),
            'pagination' => $pagination,
            'total' => count($trips),
            'attachment_counts' => $this->attachments->counts($vehicle),
            'tax_year' => $year,
            'split' => $this->split->forVehicle($user, $vehicle, $year->start, $until),
            'year_trips' => $this->trips->countVisible($user, $vehicle, $year->start, $until),
            'claim_totals' => ClaimTotals::byCurrency($claim->rows),
            // No claim value: no business trips this year, or trips without a rate in effect.
            'business_trips' => !$claim->isEmpty(),
            'sees_everyone' => $this->trips->seesEveryone($user, $vehicle),
        ]);
    }
}
