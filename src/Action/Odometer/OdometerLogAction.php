<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\Pagination;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/odometer — the mileage log: every reading (manual and
 * from fill-ups), a trend chart and plausibility warnings.
 */
final readonly class OdometerLogAction
{
    public function __construct(
        private VehicleService $vehicles,
        private OdometerService $odometer,
        private AttachmentService $attachments,
        private OdometerChart $chart,
        private OdometerWarningFlash $warnings,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $user = RequestContext::requireUser($request);
        $history = $this->odometer->history($vehicle);
        $rows = $history->newestFirst();
        $pagination = Pagination::fromQuery($request->getQueryParams(), count($rows));

        $warnings = [];
        foreach ($history->warnings as $id => $warning) {
            $warnings[$id] = ['type' => $warning->type, 'params' => $this->warnings->params($warning)];
        }

        return $this->view->render($request, $response, 'odometer/index.twig', [
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
