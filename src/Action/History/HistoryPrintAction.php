<?php

declare(strict_types=1);

namespace Logbook\Action\History;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Domain\Feature\Feature;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\History\ActivityFeed;
use Logbook\Service\History\ActivityKind;
use Logbook\Service\History\ActivityQuery;
use Logbook\Service\History\HistoryChip;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/history/print — the vehicle's whole history on one page
 * for printing or the browser's *Save as PDF* (spec.md §7.16): a service
 * history to hand to a buyer or a garage. The options are a plain GET form:
 * the kinds to include (everything but fuel until the form is sent) and
 * whether to show costs (on by default; off also hides the purchase and
 * sale prices). Milestones are always included; nothing is folded.
 */
final readonly class HistoryPrintAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ActivityFeed $feed,
        private AttachmentService $attachments,
        private OdometerService $odometer,
        private FeatureToggles $features,
        private View $view,
        private ClockInterface $clock,
        private TyreService $tyres,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams();
        $available = array_values(array_filter(
            HistoryChip::available($this->features->all()),
            static fn (HistoryChip $chip): bool => $chip !== HistoryChip::Everything,
        ));

        // Until the form is sent: everything but fuel, with costs.
        $sent = ($query['options'] ?? '') === '1';
        $picked = $sent ? (is_array($query['kinds'] ?? null) ? $query['kinds'] : []) : null;
        $chosen = array_values(array_filter(
            $available,
            static fn (HistoryChip $chip): bool => $picked === null
                ? $chip !== HistoryChip::Fuel
                : in_array($chip->value, $picked, true),
        ));
        $costs = !$sent || ($query['costs'] ?? '') === '1';

        $kinds = [ActivityKind::Milestone];
        foreach ($chosen as $chip) {
            array_push($kinds, ...$chip->kinds());
        }
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        return $this->view->render($request, $response, 'history/print.twig', [
            'vehicle' => $vehicle,
            'items' => $this->feed->items($user, new ActivityQuery([$vehicle], $kinds)),
            'attachments' => $this->attachments->index($vehicle),
            'available' => $available,
            'chosen' => $chosen,
            'costs' => $costs,
            'latest' => $this->odometer->history($vehicle)->latest(),
            'age' => VehicleAge::of($vehicle, $today),
            'today' => $today,
            'tyres' => $this->features->isEnabled(Feature::Tyres) ? $this->tyres->fitted($vehicle, $today) : [],
        ]);
    }
}
