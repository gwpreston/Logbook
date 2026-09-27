<?php

declare(strict_types=1);

namespace Logbook\Action;

use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A nested route that exists to prove deep links survive a hard refresh
 * behind a reverse proxy at a subpath (spec §11). Harmless to keep.
 */
final readonly class DeepLinkCheckAction
{
    public function __construct(private View $view)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'diagnostics/deep-link.twig');
    }
}
