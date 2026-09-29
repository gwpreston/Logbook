<?php

declare(strict_types=1);

namespace Logbook\Action\Tyre;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Domain\Tyre\TyreSeason;
use Logbook\Service\Tyre\TyreChangeForm;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/tyres/{tyre}/edit — a tyre's brand, model, size,
 * season, DOT code and notes. Never its state or position: those change
 * only through a tyre change.
 */
final readonly class EditTyreAction
{
    public function __construct(
        private VehicleService $vehicles,
        private TyreService $tyres,
        private View $view,
        private Redirector $redirect,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $tyre = TyreRoute::tyre($this->tyres, $vehicle, $request, $args);
        $preferences = RequestContext::requireUser($request)->preferences;
        $page = fn (array $values, ?ValidationErrors $errors = null, int $status = 200): ResponseInterface => $this->view->render(
            $request,
            $response,
            'tyres/tyre_form.twig',
            [
                'vehicle' => $vehicle,
                'tyre' => $tyre,
                'values' => $values,
                'errors' => $errors?->all() ?? [],
                'seasons' => TyreSeason::cases(),
            ],
            $status,
        );

        if ($request->getMethod() !== 'POST') {
            return $page(TyreChangeForm::tyreValues($tyre));
        }

        $today = LocalTime::today($this->clock, $preferences->timeZone());
        $data = TyreChangeForm::parseTyre(RequestContext::form($request), $preferences, $today);
        if ($data instanceof ValidationErrors) {
            return $page(RequestContext::formValues($request), $data, 422);
        }

        $this->tyres->updateTyre($vehicle, $tyre, $data);
        RequestContext::session($request)->flash('success', 'tyre.updated');

        return $this->redirect->backOr($request, 'tyres.index', ['id' => (string) $vehicle->id]);
    }
}
