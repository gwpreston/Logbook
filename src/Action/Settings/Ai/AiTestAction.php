<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Ai;

use Logbook\Service\Ai\ConnectionTester;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/ai/connections/{connection}/test — *Test* the connection
 * and, when chosen, one of its models (spec.md §7.25 *Test*). A model's
 * results are stored on it and shown on the connection's page; the first
 * failure is also flashed.
 */
final readonly class AiTestAction
{
    public function __construct(
        private ConnectionTester $tester,
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
        $modelId = RequestContext::form($request)['model'] ?? '';
        $model = $modelId === '' ? null : $this->route->model($request, $connection, $modelId);

        $report = $this->tester->test(RequestContext::requireUser($request), $connection, $model);
        $session = RequestContext::session($request);
        $failed = $report->firstFailure();
        if ($failed === null) {
            $session->flash('success', 'ai.test.passed');
        } else {
            $session->flash('warning', 'ai.test.failed', ['step' => $failed['step'], 'reason' => $failed['error'] ?? '']);
        }

        return $this->redirect->toRoute(
            'settings.ai.connections.show',
            ['connection' => (string) $connection->id],
            $model === null ? [] : ['model' => (string) $model->id],
        );
    }
}
