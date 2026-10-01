<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Ai;

use Logbook\Service\Ai\AiOverview;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /settings/ai/connections/{connection} — where it runs, its
 * acknowledgement, secrets, models (with a search box) and *Test*
 * (spec.md §7.25).
 */
final readonly class AiConnectionAction
{
    public function __construct(
        private AiRoute $route,
        private AiOverview $overview,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $connection = $this->route->connection($request, $args);
        $query = $request->getQueryParams();
        $search = is_string($query['q'] ?? null) ? trim(mb_substr($query['q'], 0, 100)) : '';
        $selected = is_string($query['model'] ?? null) ? $query['model'] : '';

        return $this->view->render(
            $request,
            $response,
            'settings/ai/connection.twig',
            ['selected_model' => $selected] + $this->overview->connection($connection, $search),
        );
    }
}
