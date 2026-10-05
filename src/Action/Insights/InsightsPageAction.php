<?php

declare(strict_types=1);

namespace Logbook\Action\Insights;

use Logbook\Service\Ai\Ask\AskAvailability;
use Logbook\Service\Insights\InsightsService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /insights — the Insights page (spec.md §7.26 *Ask and the Insights
 * page*, Phase 33.4): the *Ask Logbook* box when Ask is available, then
 * every computed insight (§7.8) for the user's active vehicles.
 */
final readonly class InsightsPageAction
{
    public function __construct(
        private VehicleService $vehicles,
        private InsightsService $insights,
        private AskAvailability $ask,
        private View $view,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $ask = $this->ask->isAvailable($user);

        return $this->view->render($request, $response, 'insights/index.twig', [
            'insights' => $this->insights->forVehicles($user, $this->vehicles->listFleet($user), true, $today),
            'ask_on' => $ask,
            'progress_token' => $ask ? bin2hex(random_bytes(16)) : null,
        ]);
    }
}
