<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Jobs;

use Logbook\Service\Jobs\JobsOverview;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders Settings → Jobs (the page, and a form sent back with errors or
 * a new URL token).
 */
final readonly class JobsPage
{
    public function __construct(
        private JobsOverview $overview,
        private View $view,
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $extra = [],
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'settings/jobs/index.twig', $extra + $this->overview->page(), $status);
    }
}
