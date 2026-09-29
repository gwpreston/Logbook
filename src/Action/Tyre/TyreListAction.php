<?php

declare(strict_types=1);

namespace Logbook\Action\Tyre;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\Pagination;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/tyres — the Tyres tab (spec.md §7.17): what is on the
 * vehicle, in storage by set, retired, and every change (25 per page).
 */
final readonly class TyreListAction
{
    public function __construct(
        private VehicleService $vehicles,
        private TyreService $tyres,
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
        $overview = $this->tyres->overview($vehicle, $user);
        $pagination = Pagination::fromQuery($request->getQueryParams(), count($overview->changes));

        return $this->view->render($request, $response, 'tyres/index.twig', [
            'vehicle' => $vehicle,
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
            'overview' => $overview,
            'changes' => $pagination->slice($overview->changes),
            'pagination' => $pagination,
            'kinds' => TyreChangeKind::cases(),
        ]);
    }
}
