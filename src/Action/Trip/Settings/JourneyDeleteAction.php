<?php

declare(strict_types=1);

namespace Logbook\Action\Trip\Settings;

use Logbook\Service\Trip\SavedJourneyService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/trips/journeys/{journey}/delete — confirm, then delete
 * a saved journey. Trips logged from it keep what they copied.
 */
final readonly class JourneyDeleteAction
{
    public function __construct(
        private SavedJourneyService $journeys,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $journey = $this->journeys->find($user, (int) ($args['journey'] ?? 0)) ?? throw new HttpNotFoundException($request);
        $params = ['journey' => $journey->journey()];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'back_label' => 'trip.settings.title',
                'nav' => 'settings',
                'title' => 'trip.journeys.delete_title',
                'body' => 'trip.journeys.delete_body',
                'params' => $params,
                'action' => ['settings.trips.journeys.delete', ['journey' => $journey->id]],
                'cancel' => ['settings.trips', []],
            ]);
        }

        $this->journeys->delete($user, $journey);
        RequestContext::session($request)->flash('success', 'trip.journeys.deleted', $params);

        return $this->redirect->toRoute('settings.trips');
    }
}
