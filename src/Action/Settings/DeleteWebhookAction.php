<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Webhook\WebhookService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/webhooks/{webhook}/delete — confirm, then delete one
 * of one's own webhooks with its waiting deliveries (spec.md §7.20
 * *Webhooks*). Someone else's is a 404.
 */
final readonly class DeleteWebhookAction
{
    public function __construct(
        private WebhookService $webhooks,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $webhook = $this->webhooks->webhookOf($user, (int) ($args['webhook'] ?? 0));
        if ($webhook === null) {
            throw new HttpNotFoundException($request);
        }

        if ($request->getMethod() === 'POST') {
            $this->webhooks->delete($user, $webhook);
            RequestContext::session($request)->flash('success', 'webhooks.deleted', ['name' => $webhook->name]);

            return $this->redirect->toRoute('settings.webhooks');
        }

        return $this->view->render($request, $response, 'settings/webhook_delete.twig', ['webhook' => $webhook]);
    }
}
