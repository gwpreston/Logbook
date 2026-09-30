<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Support\Http\AccessDeniedException;
use Logbook\Support\Http\RequestContext;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

/**
 * Guards every route with `{id}` (spec.md §5 *Vehicle routes*). Sits on the
 * whole signed-in group: the route declares the ability it needs as its
 * `ability` argument; this loads the vehicle once by id, asks the access
 * policy and puts the vehicle on the request as the `vehicle` attribute.
 * A vehicle that does not exist or cannot be viewed is a 404 (ids reveal
 * nothing); one that can be viewed but not used this way is a 403. A route
 * with `{id}` that declares no ability is a programming error, never an
 * open door.
 */
final readonly class VehicleAccessMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'vehicle';
    public const string ABILITY = 'ability';

    public function __construct(
        private VehicleRepository $vehicles,
        private VehicleAccess $access,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        if ($route === null || !self::isVehicleRoute($route)) {
            return $handler->handle($request);
        }

        $ability = VehicleAbility::tryFrom($route->getArgument(self::ABILITY) ?? '')
            ?? throw new LogicException(sprintf('Route "%s" has {id} but declares no vehicle ability.', $route->getPattern()));

        $user = RequestContext::requireUser($request);
        $id = $route->getArgument('id') ?? '';
        $vehicle = ctype_digit($id) ? $this->vehicles->findById((int) $id) : null;
        if ($vehicle === null || !$this->access->can($user, VehicleAbility::View, $vehicle)) {
            throw new HttpNotFoundException($request);
        }
        if (!$this->access->can($user, $ability, $vehicle)) {
            throw new AccessDeniedException($request);
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $vehicle));
    }

    public static function isVehicleRoute(RouteInterface $route): bool
    {
        return str_contains($route->getPattern(), '{id:');
    }
}
