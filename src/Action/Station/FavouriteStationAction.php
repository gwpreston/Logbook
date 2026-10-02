<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use Logbook\Service\Station\StationService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /stations/{station}/favourite — `favourite=1` adds the station to
 * the user's favourites, anything else removes it (spec.md §7.33).
 */
final readonly class FavouriteStationAction
{
    public function __construct(private StationService $stations, private Redirector $redirect)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $station = StationRoute::active($this->stations, $request, $args);
        $favourite = (RequestContext::form($request)['favourite'] ?? '') === '1';
        $this->stations->setFavourite($user, $station, $favourite);
        RequestContext::session($request)->flash('success', $favourite ? 'stations.favourited' : 'stations.unfavourited');

        return $this->redirect->backOr($request, 'stations.show', ['station' => (string) $station->id]);
    }
}
