<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Api\ApiKeyService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/api-keys/{key}/revoke — confirm, then revoke one of
 * one's own API keys (spec.md §7.20): immediate and final. Someone else's
 * key, or one already revoked, is a 404.
 */
final readonly class RevokeApiKeyAction
{
    public function __construct(
        private ApiKeyService $keys,
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
        $key = $this->keys->keyOf($user, (int) ($args['key'] ?? 0));
        if ($key === null || $key->isRevoked()) {
            throw new HttpNotFoundException($request);
        }

        if ($request->getMethod() === 'POST') {
            $this->keys->revoke($user, $key->id);
            RequestContext::session($request)->flash('success', 'api_keys.revoked', ['name' => $key->name]);

            return $this->redirect->toRoute('settings.api_keys');
        }

        return $this->view->render($request, $response, 'settings/api_key_revoke.twig', ['key' => $key]);
    }
}
