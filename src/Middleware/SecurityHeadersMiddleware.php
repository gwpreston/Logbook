<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The page security headers every response carries, on every install
 * (spec.md §10, K54, #456): no framing by another site, no content-type
 * sniffing, and the referrer sent only to the same origin. A response that
 * already sets one of them (a download's `nosniff`, a sign-in link's
 * `Referrer-Policy: no-referrer`) keeps its own value. There is no page
 * Content-Security-Policy.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const array HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        foreach (self::HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
