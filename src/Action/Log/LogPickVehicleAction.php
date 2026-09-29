<?php

declare(strict_types=1);

namespace Logbook\Action\Log;

use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /log/new/{kind} — which vehicle? Like /fuel/new (spec.md §7.3): with
 * one active vehicle straight to its form, with several a one-tap picker,
 * with none an invitation to add one. A switched-off module's kind is 404.
 */
final readonly class LogPickVehicleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private FeatureToggles $features,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kind = LogKind::tryFrom($args['kind'] ?? '');
        if ($kind === null || ($kind->feature() !== null && !$this->features->isEnabled($kind->feature()))) {
            throw new HttpNotFoundException($request);
        }

        $vehicles = $this->vehicles->listFleet(RequestContext::requireUser($request));
        if ($vehicles === []) {
            RequestContext::session($request)->flash('info', 'log.no_vehicles');

            return $this->redirect->toRoute('vehicles.create');
        }
        if (count($vehicles) === 1) {
            return $this->redirect->toRoute($kind->createRoute(), ['id' => (string) $vehicles[0]->id] + $kind->createParams());
        }

        return $this->view->render($request, $response, 'log/pick.twig', ['kind' => $kind, 'vehicles' => $vehicles]);
    }
}
