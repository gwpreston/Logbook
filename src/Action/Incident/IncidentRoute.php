<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Incident\IncidentNotFound;
use Logbook\Service\Incident\IncidentService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

final class IncidentRoute
{
    /**
     * The incident named by the route, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function incident(
        IncidentService $incidents,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): Incident {
        try {
            return $incidents->get($vehicle, (int) ($args['incident'] ?? 0));
        } catch (IncidentNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * The form values, keeping the ticked damage areas (a list).
     *
     * @return array<string, string|list<string>>
     */
    public static function formValues(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $values = [];
        foreach (is_array($body) ? $body : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }
        $areas = is_array($body) ? ($body['damage_areas'] ?? []) : [];
        $values['damage_areas'] = array_values(array_filter(is_array($areas) ? $areas : [], is_string(...)));

        return $values;
    }
}
