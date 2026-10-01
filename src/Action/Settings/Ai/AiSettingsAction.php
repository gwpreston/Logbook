<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Ai;

use Logbook\Service\Ai\AiOverview;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /settings/ai — connections, task routing, this month's usage and
 * this server's addresses (spec.md §7.25). Admins only.
 */
final readonly class AiSettingsAction
{
    public function __construct(
        private AiOverview $overview,
        private View $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($request, $response, 'settings/ai/index.twig', $this->overview->page());
    }
}
