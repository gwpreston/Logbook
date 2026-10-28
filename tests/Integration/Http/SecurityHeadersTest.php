<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Middleware\SecurityHeadersMiddleware;
use Logbook\Tests\Support\AppTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The page security headers (spec.md §10, K54, #456): every response of the
 * app carries the three, once, and a response that sets its own keeps it.
 */
final class SecurityHeadersTest extends AppTestCase
{
    public function testAPageARedirectAnErrorAndTheApiEachCarryTheThreeHeadersOnce(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        $paths = ['/login', '/vehicles', '/no-such-page', '/api/v1/vehicles', '/manifest.webmanifest'];
        foreach ($paths as $path) {
            $response = $this->get($app, $path);
            self::assertSame(['SAMEORIGIN'], $response->getHeader('X-Frame-Options'), $path);
            self::assertSame(['nosniff'], $response->getHeader('X-Content-Type-Options'), $path);
            self::assertSame(['same-origin'], $response->getHeader('Referrer-Policy'), $path);
            self::assertFalse($response->hasHeader('Content-Security-Policy'), $path);
        }
    }

    public function testAResponseThatSetsItsOwnValueKeepsIt(): void
    {
        $inner = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse()
                    ->withHeader('Referrer-Policy', 'no-referrer')
                    ->withHeader('X-Content-Type-Options', 'nosniff');
            }
        };

        $response = (new SecurityHeadersMiddleware())
            ->process((new ServerRequestFactory())->createServerRequest('GET', '/'), $inner);

        self::assertSame(['no-referrer'], $response->getHeader('Referrer-Policy'));
        self::assertSame(['nosniff'], $response->getHeader('X-Content-Type-Options'));
        self::assertSame(['SAMEORIGIN'], $response->getHeader('X-Frame-Options'));
    }
}
