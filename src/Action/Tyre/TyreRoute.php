<?php

declare(strict_types=1);

namespace Logbook\Action\Tyre;

use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreSet;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Tyre\TyreChangeNotFound;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreNotFound;
use Logbook\Service\Tyre\TyreService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /vehicles/{id}/tyres routes: the change,
 * tyre or set named by the route on this vehicle, or a 404.
 */
final class TyreRoute
{
    /**
     * @param array<string, string> $args
     */
    public static function change(
        TyreChangeService $changes,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): TyreChange {
        try {
            return $changes->get($vehicle, (int) ($args['change'] ?? 0));
        } catch (TyreChangeNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * @param array<string, string> $args
     */
    public static function tyre(TyreService $tyres, Vehicle $vehicle, ServerRequestInterface $request, array $args): Tyre
    {
        try {
            return $tyres->tyre($vehicle, (int) ($args['tyre'] ?? 0));
        } catch (TyreNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * @param array<string, string> $args
     */
    public static function set(TyreService $tyres, Vehicle $vehicle, ServerRequestInterface $request, array $args): TyreSet
    {
        try {
            return $tyres->set($vehicle, (int) ($args['set'] ?? 0));
        } catch (TyreNotFound) {
            throw new HttpNotFoundException($request);
        }
    }
}
