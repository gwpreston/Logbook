<?php

declare(strict_types=1);

namespace Logbook\Action\Pwa;

use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /offline — what the service worker shows for a page it cannot load
 * without a connection (spec.md §7.15). Rendered signed out: it is cached
 * once and shown to whoever is offline.
 */
final readonly class OfflineAction
{
    public function __construct(private View $view)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->view->fetch('pwa/offline.twig'));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache');
    }
}
