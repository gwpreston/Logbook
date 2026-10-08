<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Webhook\WebhookService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/webhooks/{webhook}/{action} — *Send test*, *Pause*,
 * *Resume* and *New secret* on one of one's own webhooks (spec.md §7.20
 * *Webhooks*, #292). *New secret* answers with the page, the secret shown
 * once; the others redirect back with a message. Someone else's webhook is
 * a 404.
 */
final readonly class WebhookAction
{
    public function __construct(
        private WebhookService $webhooks,
        private WebhooksAction $page,
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
        $session = RequestContext::session($request);
        $name = ['name' => $webhook->name];

        switch ($args['action'] ?? '') {
            case 'secret':
                $secret = $this->webhooks->newSecret($user, $webhook);

                return $this->page->render($request, $response, ['events' => []], null, [
                    'webhook' => $this->webhooks->webhookOf($user, $webhook->id) ?? $webhook,
                    'secret' => $secret,
                    'renewed' => true,
                ]);
            case 'test':
                $result = $this->webhooks->test($user, $webhook);
                $result->delivered
                    ? $session->flash('success', 'webhooks.test_sent', $name)
                    : $session->flash('error', 'webhooks.test_failed', $name + ['error' => (string) $result->error]);
                break;
            case 'pause':
                $this->webhooks->pause($user, $webhook);
                $session->flash('success', 'webhooks.paused', $name);
                break;
            case 'resume':
                $this->webhooks->resume($user, $webhook)
                    ? $session->flash('success', 'webhooks.resumed', $name)
                    : $session->flash('error', 'webhooks.needs_secret', $name);
                break;
            default:
                throw new HttpNotFoundException($request);
        }

        return $this->redirect->toRoute('settings.webhooks');
    }
}
