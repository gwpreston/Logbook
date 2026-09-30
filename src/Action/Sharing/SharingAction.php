<?php

declare(strict_types=1);

namespace Logbook\Action\Sharing;

use Logbook\Service\Sharing\SharingService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/sharing — who else is on this vehicle (spec.md
 * §7.21). Anyone who can see the vehicle gets the page (GET: `View`);
 * adding a share is the owner's (POST: its own route, `Own`).
 */
final readonly class SharingAction
{
    public function __construct(
        private SharingService $sharing,
        private SharingPage $page,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, $vehicle);
        }

        $form = RequestContext::form($request);
        $username = is_string($form['username'] ?? null) ? trim($form['username']) : '';
        $share = ShareForm::parse($form);
        $refusal = $username === ''
            ? null
            : $this->sharing->add($vehicle, $username, $share->level, $share->canSeeCosts, $share->notify);
        if ($username === '' || $refusal !== null) {
            $key = $refusal === null ? 'sharing.refused.username_required' : 'sharing.refused.' . $refusal->value;

            $errors = ['username' => ['key' => $key, 'params' => []]];

            return $this->page->render($request, $response, $vehicle, RequestContext::formValues($request), $errors, 422);
        }

        RequestContext::session($request)->flash('success', 'sharing.added', ['username' => mb_strtolower($username)]);

        return $this->redirect->toRoute('vehicles.sharing', ['id' => (string) $vehicle->id]);
    }
}
