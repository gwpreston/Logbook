<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiProblem;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A list's `?vehicle=` filter: a vehicle the key's user can see, or none;
 * any other id is a 404, like a vehicle route's (spec.md §5).
 */
final class VehicleFilter
{
    public static function fromRequest(ServerRequestInterface $request, ApiReader $reader, User $user): ?Vehicle
    {
        $raw = $request->getQueryParams()['vehicle'] ?? null;
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,18}$/', $raw) !== 1) {
            throw ApiProblem::invalidParameter('vehicle', 'a vehicle id.');
        }

        return $reader->visibleVehicle($user, (int) $raw) ?? throw ApiProblem::notFound('There is no such vehicle.');
    }
}
