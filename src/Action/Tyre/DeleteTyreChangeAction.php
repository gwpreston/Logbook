<?php

declare(strict_types=1);

namespace Logbook\Action\Tyre;

use Logbook\Action\EntryGuard;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Tyre\TyreSummary;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/tyres/changes/{change}/delete — confirm (works
 * without JS; lists the tyres that go with it), then delete the change with
 * its lines and reading. Refused when later changes depend on it.
 */
final readonly class DeleteTyreChangeAction
{
    public function __construct(
        private TyreChangeService $changes,
        private TyreService $tyres,
        private TyreFormPage $page,
        private DisplayFormatter $formatter,
        private View $view,
        private Redirector $redirect,
        private EntryGuard $guard,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $change = TyreRoute::change($this->changes, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $change->createdBy);
        $byId = [];
        foreach ($this->tyres->tyres($vehicle) as $tyre) {
            $byId[$tyre->id] = $tyre;
        }
        $unit = RequestContext::requireUser($request)->preferences->depthUnit;
        $summary = TyreSummary::line($change, $byId, TyreService::setsById($this->tyres->sets($vehicle)), $unit);
        $params = ['summary' => $summary, 'date' => $this->formatter->date($change->data->doneOn)];

        $error = null;
        if ($request->getMethod() === 'POST') {
            try {
                $this->changes->delete($vehicle, $change);
                RequestContext::session($request)->flash('success', 'tyre.change.deleted');

                return $this->redirect->toRoute('tyres.index', ['id' => (string) $vehicle->id]);
            } catch (TyreChangeRefused $refused) {
                $error = $this->page->errors($refused)->all()[$refused->field] ?? null;
            }
        }

        return $this->view->render($request, $response, 'tyres/change_delete.twig', [
            'vehicle' => $vehicle,
            'change' => $change,
            'params' => $params,
            'orphans' => $this->changes->createdBy($vehicle, $change),
            'error' => $error,
        ], $error === null ? 200 : 422);
    }
}
