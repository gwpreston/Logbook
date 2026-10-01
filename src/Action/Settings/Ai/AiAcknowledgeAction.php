<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Ai;

use Logbook\Service\Ai\AiAdmin;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/ai/connections/{connection}/acknowledge — the admin
 * accepts that data will be sent to an internet host (spec.md §7.25).
 * Recorded with who, when and the URL.
 */
final readonly class AiAcknowledgeAction
{
    public function __construct(
        private AiAdmin $admin,
        private AiRoute $route,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $connection = $this->route->connection($request, $args);
        if ((RequestContext::form($request)['acknowledge'] ?? '') === '1') {
            $this->admin->acknowledge(RequestContext::requireUser($request), $connection);
            RequestContext::session($request)->flash('success', 'ai.acknowledge.saved');
        }

        return $this->redirect->toRoute('settings.ai.connections.show', ['connection' => (string) $connection->id]);
    }
}
