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
 * POST /settings/ai/this-host — the addresses that are this server
 * (spec.md §7.25 *Where it runs*).
 */
final readonly class AiThisHostAction
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
        $text = RequestContext::form($request)['this_host'] ?? '';
        $errors = $this->admin->saveThisHost(is_string($text) ? $text : '');
        if ($errors !== null) {
            return $this->view->render($request, $response, 'settings/ai/index.twig', [
                'this_host_value' => is_string($text) ? $text : '',
                'this_host_errors' => $errors->all(),
            ] + $this->overview->page(), 422);
        }
        RequestContext::session($request)->flash('success', 'ai.this_host.saved');

        return $this->redirect->toRoute('settings.ai');
    }
}
