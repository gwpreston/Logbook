<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerReadingNotFound;
use Logbook\Service\Odometer\OdometerService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /vehicles/{id}/odometer/{reading} routes.
 */
final class OdometerRoute
{
    /**
     * The reading named by the route on this vehicle, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function reading(
        OdometerService $odometer,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): OdometerReading {
        try {
            return $odometer->get($vehicle, (int) ($args['reading'] ?? 0));
        } catch (OdometerReadingNotFound) {
            throw new HttpNotFoundException($request);
        }
    }
}
