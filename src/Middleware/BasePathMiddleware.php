<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\BasePath;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Makes routing independent of how the reverse proxy forwards the subpath.
 *
 * Slim's router is configured with APP_BASE_PATH, so it expects every path to
 * carry that prefix. Proxies differ: some forward "/logbook/vehicles" as-is,
 * others strip it to "/vehicles". This middleware restores the prefix when it
 * is missing, so deep links — and a hard refresh on them — route correctly
 * either way, and generated URLs always include the prefix.
 */
final readonly class BasePathMiddleware implements MiddlewareInterface
{
    private string $basePath;

    public function __construct(AppSettings $settings)
    {
        $this->basePath = $settings->basePath;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $path = BasePath::ensurePrefixed($this->basePath, $uri->getPath());

        if ($path !== $uri->getPath()) {
            $request = $request->withUri($uri->withPath($path));
        }

        return $handler->handle($request->withAttribute('basePath', $this->basePath));
    }
}
