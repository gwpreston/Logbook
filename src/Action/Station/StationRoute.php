<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use Logbook\Domain\Station\Station;
use Logbook\Service\Station\StationService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

final class StationRoute
{
    /**
     * The station named by the route as it is (merged or not), or a 404.
     *
     * @param array<string, string> $args
     */
    public static function station(StationService $stations, ServerRequestInterface $request, array $args): Station
    {
        return $stations->find((int) ($args['station'] ?? 0)) ?? throw new HttpNotFoundException($request);
    }

    /**
     * The station named by the route, unmerged, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function active(StationService $stations, ServerRequestInterface $request, array $args): Station
    {
        $station = self::station($stations, $request, $args);
        if ($station->isMerged()) {
            throw new HttpNotFoundException($request);
        }

        return $station;
    }
}
