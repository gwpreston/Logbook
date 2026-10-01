<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Ai;

use Logbook\Service\Ai\AiAdmin;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/ai/connections/{connection}/delete — confirm, then
 * delete a connection with its models and secrets; its tasks become
 * unassigned (spec.md §7.25).
 */
final readonly class AiConnectionDeleteAction
{
    public function __construct(
        private AiAdmin $admin,
        private AiRoute $route,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $connection = $this->route->connection($request, $args);
        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'settings/ai/connection_delete.twig', ['connection' => $connection]);
        }

        $this->admin->delete($connection);
        RequestContext::session($request)->flash('success', 'ai.connection.deleted', ['name' => $connection->name]);

        return $this->redirect->toRoute('settings.ai');
    }
}
