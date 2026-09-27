<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\FuelEntryNotFound;
use Logbook\Service\Fuel\FuelService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /vehicles/{id}/fuel/{entry} routes.
 */
final class FuelRoute
{
    /**
     * The fill-up named by the route on this vehicle, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function entry(FuelService $fuel, Vehicle $vehicle, ServerRequestInterface $request, array $args): FuelEntry
    {
        try {
            return $fuel->get($vehicle, (int) ($args['entry'] ?? 0));
        } catch (FuelEntryNotFound) {
            throw new HttpNotFoundException($request);
        }
    }
}
