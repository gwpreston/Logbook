<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Support\Cache\RequestReads;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets the repositories remember what a page request has read (spec.md §8
 * *Page budgets*). Only for GET and HEAD: a request that changes data reads
 * straight from the database.
 */
final readonly class RequestReadsMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestReads $reads)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $this->reads->begin();
        try {
            return $handler->handle($request);
        } finally {
            $this->reads->end();
        }
    }
}
