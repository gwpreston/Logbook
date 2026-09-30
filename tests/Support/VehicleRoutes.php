<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Middleware\VehicleAccessMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Interfaces\RouteInterface;

/**
 * Every /vehicles/{id} route, and a request to one for a given vehicle
 * (other placeholders filled with a plausible value), for the access tests.
 */
trait VehicleRoutes
{
    /**
     * @param App<ContainerInterface> $app
     * @return list<RouteInterface>
     */
    private function vehicleRoutes(App $app): array
    {
        return array_values(array_filter(
            $app->getRouteCollector()->getRoutes(),
            static fn (RouteInterface $route): bool => VehicleAccessMiddleware::isVehicleRoute($route),
        ));
    }

    /**
     * GET when the route answers it, else POST (with the CSRF token).
     *
     * @param array<string, int> $ids values for other placeholders, e.g. ['attachment' => 3]
     */
    private function requestRoute(
        TestBrowser $browser,
        RouteInterface $route,
        Vehicle $vehicle,
        array $ids = [],
    ): ResponseInterface {
        $url = (string) preg_replace_callback(
            '/\{(\w+)(?::((?:[^{}]|\{\d+\})+))?\}/',
            static function (array $m) use ($vehicle, $ids): string {
                $regex = $m[2] ?? '[0-9]+';

                return match (true) {
                    $m[1] === 'id' => (string) $vehicle->id,
                    isset($ids[$m[1]]) => (string) $ids[$m[1]],
                    str_starts_with($regex, '[0-9]') => '1',
                    str_starts_with($regex, '[a-f0-9]') => str_repeat('a', 32),
                    default => explode('|', $regex)[0],
                };
            },
            $route->getPattern(),
        );

        return in_array('GET', $route->getMethods(), true) ? $browser->get($url) : $browser->post($url);
    }
}
