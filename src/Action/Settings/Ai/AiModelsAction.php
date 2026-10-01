<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Ai;

use Logbook\Domain\Ai\Capability;
use Logbook\Service\Ai\AiAdmin;
use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\AiGateway;
use Logbook\Service\Ai\AiModelsRefresh;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/ai/connections/{connection}/models — *Refresh models*,
 * add a model (listed or typed), take one off, or save its capabilities
 * (spec.md §7.25 *Models*).
 */
final readonly class AiModelsAction
{
    public function __construct(
        private AiAdmin $admin,
        private AiModelsRefresh $refresh,
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
        $input = RequestContext::form($request);
        $session = RequestContext::session($request);
        $back = ['connection' => (string) $connection->id];
        $query = is_string($input['q'] ?? null) && $input['q'] !== '' ? ['q' => $input['q']] : [];

        switch ($input['do'] ?? '') {
            case 'refresh':
                try {
                    $count = $this->refresh->refresh($connection);
                    $session->flash('success', 'ai.models.refreshed', ['count' => $count]);
                } catch (AiFailure $e) {
                    $session->flash('error', 'ai.models.refresh_failed', [
                        'reason' => $e->detail === '' ? $e->error->value : $e->detail,
                    ]);
                }
                break;
            case 'add':
                $name = is_string($input['name'] ?? null) ? $input['name'] : '';
                $id = $this->admin->addModel($connection, $name);
                $session->flash($id === null ? 'error' : 'success', $id === null ? 'ai.models.add_invalid' : 'ai.models.added', [
                    'name' => mb_substr(trim($name), 0, 200),
                ]);
                break;
            case 'remove':
                $model = $this->route->model($request, $connection, $input['model'] ?? null);
                $this->admin->removeModel($model);
                $session->flash('success', 'ai.models.removed', ['name' => $model->name]);
                break;
            case 'capabilities':
                $model = $this->route->model($request, $connection, $input['model'] ?? null);
                $ticked = is_array($input['capabilities'] ?? null) ? $input['capabilities'] : [];
                $this->admin->setCapabilities($model, array_values(array_filter(
                    Capability::cases(),
                    static fn (Capability $c): bool => in_array($c->value, $ticked, true),
                )));
                $session->flash('success', 'ai.models.capabilities_saved', ['name' => $model->name]);
                break;
        }

        return $this->redirect->toRoute('settings.ai.connections.show', $back, $query);
    }
}
