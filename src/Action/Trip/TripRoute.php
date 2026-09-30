<?php

declare(strict_types=1);

namespace Logbook\Action\Trip;

use Logbook\Domain\Trip\Trip;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Trip\TripNotFound;
use Logbook\Service\Trip\TripService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /vehicles/{id}/trips routes.
 */
final class TripRoute
{
    /**
     * The trip named by the route, or a 404: also for another driver's trip
     * this user may not see (spec.md §7.22 *Access*).
     *
     * @param array<string, string> $args
     */
    public static function trip(
        TripService $trips,
        User $user,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): Trip {
        try {
            return $trips->get($user, $vehicle, (int) ($args['entry'] ?? 0));
        } catch (TripNotFound) {
            throw new HttpNotFoundException($request);
        }
    }
}
