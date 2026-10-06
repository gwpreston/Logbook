<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Service\Demo\DemoBootstrap;
use Logbook\Service\Demo\DemoMode;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The outermost demo middleware (spec.md §7.36): on a bare-PHP install it is
 * the web start path that seeds an empty database set up for the demo, and it
 * puts `X-Robots-Tag: noindex, nofollow` on every response of an active demo.
 * A no-op unless `DEMO_MODE` is set.
 */
final readonly class DemoMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DemoMode $mode,
        private DemoBootstrap $bootstrap,
        private ResponseFactoryInterface $responses,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $ready = $this->bootstrap->ensure(throttle: true);
        } catch (Throwable $e) {
            // Outside the error middleware: a failed first seeding is a controlled 503, tried again next time.
            $this->logger->error('The demo could not be seeded: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $ready = false;
        }
        if (!$ready) {
            $response = $this->responses->createResponse(503)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Retry-After', '10');
            $response->getBody()->write("The demo is being set up. Try again in a moment.\n");

            return $response;
        }

        $response = $handler->handle($request);

        return $this->mode->isActive() ? $response->withHeader('X-Robots-Tag', 'noindex, nofollow') : $response;
    }
}
