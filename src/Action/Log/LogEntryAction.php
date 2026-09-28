<?php

declare(strict_types=1);

namespace Logbook\Action\Log;

use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /log/new — "Log something" (spec.md §7.3): one choice per kind of
 * entry, leaving out switched-off modules. Behind the sidebar's "+ Log
 * entry" and the tab bar's "+"; a modal on desktop.
 */
final readonly class LogEntryAction
{
    public function __construct(
        private View $view,
        private FeatureToggles $features,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $enabled = $this->features->all();
        $kinds = array_values(array_filter(
            LogKind::cases(),
            static fn (LogKind $kind): bool => $kind->feature() === null || $enabled[$kind->feature()->value],
        ));

        return $this->view->render($request, $response, 'log/chooser.twig', ['kinds' => $kinds]);
    }
}
