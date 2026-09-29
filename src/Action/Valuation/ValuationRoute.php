<?php

declare(strict_types=1);

namespace Logbook\Action\Valuation;

use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Valuation\ValuationNotFound;
use Logbook\Service\Valuation\ValuationService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /vehicles/{id}/valuations routes.
 */
final class ValuationRoute
{
    /**
     * The valuation named by the route on this vehicle, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function valuation(
        ValuationService $valuations,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): VehicleValuation {
        try {
            return $valuations->get($vehicle, (int) ($args['entry'] ?? 0));
        } catch (ValuationNotFound) {
            throw new HttpNotFoundException($request);
        }
    }
}
