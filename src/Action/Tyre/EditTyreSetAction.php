<?php

declare(strict_types=1);

namespace Logbook\Action\Tyre;

use Logbook\Service\Tyre\TyreChangeForm;
use Logbook\Service\Tyre\TyreService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/tyres/sets/{set}/edit — rename a tyre set or
 * change where it is kept. Sets are created on the Swap set and Remove forms.
 */
final readonly class EditTyreSetAction
{
    public function __construct(
        private TyreService $tyres,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $set = TyreRoute::set($this->tyres, $vehicle, $request, $args);
        $preferences = RequestContext::requireUser($request)->preferences;
        $page = fn (array $values, ?ValidationErrors $errors = null, int $status = 200): ResponseInterface => $this->view->render(
            $request,
            $response,
            'tyres/set_form.twig',
            [
                'vehicle' => $vehicle,
                'set' => $set,
                'values' => $values,
                'errors' => $errors?->all() ?? [],
                'in_use' => $this->tyres->isInUse($vehicle, $set),
            ],
            $status,
        );

        if ($request->getMethod() !== 'POST') {
            return $page([
                'name' => $set->data->name,
                'storage_location' => $set->data->storageLocation ?? '',
                'notes' => $set->data->notes ?? '',
            ]);
        }

        $data = TyreChangeForm::parseSet(RequestContext::form($request), $preferences);
        if ($data instanceof ValidationErrors) {
            return $page(RequestContext::formValues($request), $data, 422);
        }

        $this->tyres->updateSet($vehicle, $set, $data);
        RequestContext::session($request)->flash('success', 'tyre.set.updated');

        return $this->redirect->backOr($request, 'tyres.index', ['id' => (string) $vehicle->id]);
    }
}
