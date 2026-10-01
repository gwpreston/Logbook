<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Ai;

use Logbook\Service\Ai\AiAdmin;
use Logbook\Service\Ai\AiOverview;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/ai/tasks — which model does which job (spec.md §7.25
 * *Tasks*). A model without what the task needs is refused.
 */
final readonly class AiTasksAction
{
    public function __construct(
        private AiAdmin $admin,
        private AiOverview $overview,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $errors = $this->admin->saveTasks(RequestContext::form($request), RequestContext::locale($request));
        if ($errors !== null) {
            return $this->view->render($request, $response, 'settings/ai/index.twig', [
                'task_values' => RequestContext::formValues($request),
                'task_errors' => $errors->all(),
            ] + $this->overview->page(), 422);
        }
        RequestContext::session($request)->flash('success', 'ai.task.saved');

        return $this->redirect->toRoute('settings.ai');
    }
}
