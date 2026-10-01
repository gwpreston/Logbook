<?php

declare(strict_types=1);

namespace Logbook\Action\Garage;

use Logbook\Service\Attention\AttentionList;
use Logbook\Service\Reminder\DueCounter;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\User\UserDirectory;
use Logbook\Service\Vehicle\VehicleSnapshot;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Service\Vehicle\VehicleSnapshots;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /garage — the vehicles one can see as cards with their due badge,
 * odometer and economy (spec.md §7.1): one's own, then those shared with
 * one, naming the owner and one's level (§7.21). Archived vehicles are
 * listed only with `?archived=1`.
 */
final readonly class GarageAction
{
    public function __construct(
        private View $view,
        private VehicleService $vehicles,
        private VehicleSnapshots $snapshots,
        private DueCounter $counter,
        private SharingService $sharing,
        private UserDirectory $directory,
        private AttentionList $attention,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $showArchived = ($request->getQueryParams()['archived'] ?? '') === '1';
        $vehicles = $this->vehicles->listFleet($user, $showArchived);
        // Needs attention first: it brings the reminders up to date (spec.md §7.24).
        $attention = $this->attention->forVehicles($user, $vehicles)->counts();
        $snapshots = $this->snapshots->of($vehicles, $this->counter->counts($user), $attention);
        $own = array_values(array_filter($snapshots, static fn (VehicleSnapshot $s): bool => $s->vehicle->userId === $user->id));
        $shared = [];
        foreach ($snapshots as $snapshot) {
            $share = $snapshot->vehicle->userId === $user->id ? null : $this->sharing->shareOf($user, $snapshot->vehicle);
            if ($share !== null) {
                $shared[] = [
                    'snapshot' => $snapshot,
                    'owner' => $this->directory->displayName($snapshot->vehicle->userId) ?? '',
                    'level' => $share->level,
                ];
            }
        }

        return $this->view->render($request, $response, 'garage/index.twig', [
            'vehicles' => $own,
            'shared' => $shared,
            'counts' => $this->vehicles->counts($user),
            'show_archived' => $showArchived,
        ]);
    }
}
