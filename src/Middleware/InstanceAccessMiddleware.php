<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Service\Access\InstanceAccess;
use Logbook\Support\Http\AccessDeniedException;
use Logbook\Support\Http\RequestContext;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;

/**
 * Guards the install-wide pages (spec.md §5 *Access policy*): a route that
 * declares an `instance` argument needs that InstanceAbility, else 403
 * (404 for an ability that hides its pages, such as Settings → AI).
 * Sits on the whole signed-in group, next to VehicleAccessMiddleware.
 */
final readonly class InstanceAccessMiddleware implements MiddlewareInterface
{
    public const string ABILITY = 'instance';

    public function __construct(private InstanceAccess $access)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $declared = RouteContext::fromRequest($request)->getRoute()?->getArgument(self::ABILITY);
        if ($declared === null) {
            return $handler->handle($request);
        }

        $ability = InstanceAbility::tryFrom($declared)
            ?? throw new LogicException(sprintf('Unknown instance ability "%s".', $declared));
        if (!$this->access->can(RequestContext::requireUser($request), $ability)) {
            throw $ability->isHidden() ? new HttpNotFoundException($request) : new AccessDeniedException($request);
        }

        return $handler->handle($request);
    }
}
