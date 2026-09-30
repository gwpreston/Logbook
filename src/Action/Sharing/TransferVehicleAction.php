<?php

declare(strict_types=1);

namespace Logbook\Action\Sharing;

use Logbook\Service\Sharing\SharingService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/transfer — hand the vehicle to another user, who
 * becomes its owner (spec.md §7.21; `Own`). The form is its own
 * confirmation page; the old owner keeps Manage unless they untick it.
 */
final readonly class TransferVehicleAction
{
    public function __construct(
        private SharingService $sharing,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $values = ['keep_access' => '1'];
        $errors = [];

        if ($request->getMethod() === 'POST') {
            $form = RequestContext::form($request);
            $username = is_string($form['username'] ?? null) ? trim($form['username']) : '';
            $keep = ($form['keep_access'] ?? '') === '1';
            $refusal = $username === '' ? null : $this->sharing->transfer($vehicle, $username, $keep);
            if ($username !== '' && $refusal === null) {
                RequestContext::session($request)->flash('success', 'sharing.transferred', [
                    'name' => $vehicle->name(),
                    'username' => mb_strtolower($username),
                ]);

                return $keep
                    ? $this->redirect->toRoute('vehicles.show', ['id' => (string) $vehicle->id])
                    : $this->redirect->toRoute('garage');
            }
            $values = RequestContext::formValues($request);
            $key = $refusal === null ? 'sharing.refused.username_required' : 'sharing.refused.' . $refusal->value;
            $errors['username'] = ['key' => $key, 'params' => []];
        }

        return $this->view->render($request, $response, 'vehicles/transfer.twig', [
            'vehicle' => $vehicle,
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }
}
