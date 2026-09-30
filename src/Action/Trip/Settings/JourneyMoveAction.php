<?php

declare(strict_types=1);

namespace Logbook\Action\Trip\Settings;

use Logbook\Service\Trip\SavedJourneyService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/trips/journeys/{journey}/move/{direction} — reorder saved
 * journeys one place at a time (works without JS).
 */
final readonly class JourneyMoveAction
{
    public function __construct(
        private SavedJourneyService $journeys,
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
        $this->journeys->move($user, $journey, ($args['direction'] ?? '') === 'up' ? -1 : 1);

        return $this->redirect->toRoute('settings.trips');
    }
}
