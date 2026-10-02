<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Places;

use Logbook\Service\Station\PlaceService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /settings/places — the signed-in user's own places (spec.md §7.33
 * *Places*), private to them.
 */
final readonly class PlacesAction
{
    public function __construct(private PlaceService $places, private View $view)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($request, $response, 'settings/places/index.twig', [
            'places' => $this->places->list(RequestContext::requireUser($request)),
        ]);
    }
}
