<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Route-group gate for a module (spec.md §7.10): while the module is
 * switched off its pages do not exist (404), so bookmarks and deep links
 * cannot reach them. Runs inside the auth guard, so a signed-out visitor
 * learns nothing about which modules are on.
 */
final readonly class FeatureGateMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Feature $feature,
        private FeatureToggles $features,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->features->isEnabled($this->feature)) {
            throw new HttpNotFoundException($request);
        }

        return $handler->handle($request);
    }
}
