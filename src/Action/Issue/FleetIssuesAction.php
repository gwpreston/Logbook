<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Issue\IssueService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /issues — every visible active vehicle's open and watching issues,
 * safety first, each naming its vehicle (spec.md §7.37 *Fleet*), linked
 * from the *Needs attention* widget.
 */
final readonly class FleetIssuesAction
{
    public function __construct(
        private IssueService $issues,
        private VehicleAccess $access,
        private VehicleRepository $vehicles,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $ids = $this->access->visibleVehicleIds($user, VehicleScope::Active);
        $vehicles = [];
        foreach ($this->vehicles->listByIds($ids) as $vehicle) {
            $vehicles[$vehicle->id] = $vehicle;
        }

        return $this->view->render($request, $response, 'issues/fleet.twig', [
            'issues' => $this->issues->listFor($ids, [IssueStatus::Open, IssueStatus::Watching]),
            'vehicles' => $vehicles,
            'several' => count($vehicles) > 1,
        ]);
    }
}
