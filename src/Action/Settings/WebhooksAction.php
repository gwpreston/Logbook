<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\Webhook\Webhook;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Service\Webhook\WebhookService;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/webhooks — one's entry webhooks and *Add webhook*
 * (spec.md §7.20 *Webhooks*, Phase 39.3). A new webhook's signing secret is
 * shown on the page that answers the POST, once, and never put in the
 * session; the page is not cached, so going back cannot show it again.
 */
final readonly class WebhooksAction
{
    public function __construct(
        private WebhookService $webhooks,
        private View $view,
        private AppSettings $settings,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $values = ['events' => array_map(static fn (WebhookEvent $e): string => $e->value, WebhookEvent::cases())];
        $errors = null;
        $shown = null;

        if ($request->getMethod() === 'POST') {
            $parsed = $this->webhooks->parse($user, RequestContext::form($request));
            if ($parsed instanceof ValidationErrors) {
                $form = RequestContext::form($request);
                $chosen = is_array($form['events'] ?? null) ? $form['events'] : [];
                $values = RequestContext::formValues($request)
                    + ['events' => array_values(array_filter($chosen, is_string(...)))];
                $errors = $parsed;
            } else {
                $created = $this->webhooks->create($user, $parsed['name'], $parsed['url'], $parsed['events']);
                $shown = ['webhook' => $created['webhook'], 'secret' => $created['secret']];
                $values = $values + ['name' => '', 'url' => ''];
            }
        }

        return $this->render($request, $response, $values, $errors, $shown);
    }

    /**
     * The page, with a secret to show once when one was just made (here or
     * by *New secret*).
     *
     * @param array<string, mixed> $values
     * @param array{webhook: Webhook, secret: string, renewed?: bool}|null $shown
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $values,
        ?ValidationErrors $errors,
        ?array $shown,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);
        $webhooks = $this->webhooks->webhooksOf($user);

        return $this->view->render($request, $response, 'settings/webhooks.twig', [
            'webhooks' => $webhooks,
            'waiting' => $this->webhooks->waiting($webhooks),
            'events' => WebhookEvent::cases(),
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'shown' => $shown,
            'webhooks_enabled' => $this->settings->webhooksEnabled,
            'api_enabled' => $this->settings->apiEnabled,
            'can_store' => $this->webhooks->canStoreSecrets(),
            'pause_after' => Webhook::PAUSE_AFTER,
        ], $errors === null ? 200 : 422)->withHeader('Cache-Control', 'no-store');
    }
}
