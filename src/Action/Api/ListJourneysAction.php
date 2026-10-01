<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Trip\SavedJourneyService;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/journeys — the key user's saved journeys, in their Settings →
 * Trips order (spec.md §7.20, Phase 23.1), so a Shortcut can offer them and
 * log one with `journey_id`.
 */
final readonly class ListJourneysAction
{
    public function __construct(
        private SavedJourneyService $journeys,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);

        return $this->responder->json([
            'items' => array_map(Serializer::savedJourney(...), $this->journeys->forUser($user)),
        ]);
    }
}
