<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Trip\MileageSplit;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\Pagination;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/odometer — the mileage log: every reading (manual and
 * from fill-ups), a trend chart and plausibility warnings. With trips on,
 * the summary gains this tax year's business and private split (Phase 22).
 */
final readonly class OdometerLogAction
{
    public function __construct(
        private OdometerService $odometer,
        private AttachmentService $attachments,
        private OdometerChart $chart,
        private OdometerWarningFlash $warnings,
        private View $view,
        private FeatureToggles $features,
        private MileageSplit $split,
        private ClaimReportService $claims,
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
        $history = $this->odometer->history($vehicle);
        $rows = $history->newestFirst();
        $pagination = Pagination::fromQuery($request->getQueryParams(), count($rows));

        $warnings = [];
        foreach ($history->warnings as $id => $warning) {
            $warnings[$id] = ['type' => $warning->type, 'params' => $this->warnings->params($warning)];
        }

        $trips = null;
        if ($this->features->isEnabled(Feature::Trips)) {
            $today = LocalTime::today($this->clock, $user->preferences->timeZone());
            $year = $this->claims->taxYearOf($user, $today);
            $trips = [
                'tax_year' => $year,
                'split' => $this->split->forVehicle($user, $vehicle, $year->start, min($today, $year->lastDay())),
            ];
        }

        return $this->view->render($request, $response, 'odometer/index.twig', [
            'trips' => $trips,
            'vehicle' => $vehicle,
            'history' => $history,
            'rows' => $pagination->slice($rows),
            'pagination' => $pagination,
            'deltas' => $history->deltas(),
            'warnings' => $warnings,
            'attachment_counts' => $this->attachments->counts($vehicle),
            'chart' => $this->chart->build($history, $user->preferences),
            // To the latest reading's date, not today (spec.md §7.2).
            'per_year' => VehicleAge::lifetimeAverageKmPerYear($vehicle, $history->latest(), $user->preferences->timeZone()),
        ]);
    }
}
