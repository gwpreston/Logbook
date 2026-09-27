<?php

declare(strict_types=1);

namespace Logbook\Action;

use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HomeAction
{
    public function __construct(private View $view)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'home.twig', [
            'vehicle_count' => 0,
        ]);
    }
}
