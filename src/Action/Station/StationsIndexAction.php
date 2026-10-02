<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use Logbook\Service\Station\PlaceService;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /stations?q= — every station (spec.md §7.33 *Stations page*):
 * favourites first, then by last visit, with the distance from each of the
 * user's places, visits, last visit and the average paid in the last 12
 * months for their most-used grade there.
 */
final readonly class StationsIndexAction
{
    public function __construct(
        private StationService $stations,
        private PlaceService $places,
        private View $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams()['q'] ?? '';
        $query = is_string($query) ? mb_substr(trim($query), 0, 100) : '';
        $places = $this->places->list($user);

        $rows = [];
        foreach ($this->stations->listing($user, $query) as $listing) {
            $rows[] = [
                'listing' => $listing,
                'distances' => PlaceService::distances($places, $listing->station),
            ];
        }

        return $this->view->render($request, $response, 'stations/index.twig', [
            'rows' => $rows,
            'query' => $query,
            'places' => $places,
        ]);
    }
}
