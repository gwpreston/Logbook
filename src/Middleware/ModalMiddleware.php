<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Modal forms (spec.md §5): a script cannot read where a redirect was going
 * (fetch either follows it — consuming the flash message on the way — or
 * hides it), so for a request made from the modal a redirect becomes
 * `204 No Content` with the target in `X-Logbook-Location`. The script then
 * closes the dialog and follows it (or, after a GET, loads it into the
 * dialog). Ordinary requests are untouched.
 */
final readonly class ModalMiddleware implements MiddlewareInterface
{
    public const string LOCATION_HEADER = 'X-Logbook-Location';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if (!View::isModal($request)) {
            return $response;
        }

        $status = $response->getStatusCode();
        if ($status < 300 || $status >= 400 || !$response->hasHeader('Location')) {
            return $response;
        }

        return $response
            ->withStatus(204)
            ->withHeader(self::LOCATION_HEADER, $response->getHeaderLine('Location'))
            ->withoutHeader('Location')
            ->withAddedHeader('Vary', View::MODAL_HEADER);
    }
}
