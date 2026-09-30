<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\Api\ApiScope;
use Logbook\Service\Api\ApiKeyForm;
use Logbook\Service\Api\ApiKeyService;
use Logbook\Service\Api\CreatedApiKey;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\AbsoluteUrl;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/api-keys — one's API keys and *Create key* (spec.md
 * §7.20). A created key's token is shown on the page that answers the
 * POST, once, and never stored or put in the session; the page is not
 * cached, so going back cannot show it again.
 */
final readonly class ApiKeysAction
{
    public function __construct(
        private ApiKeyService $keys,
        private View $view,
        private AppSettings $settings,
        private AbsoluteUrl $urls,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $values = ['scope' => ApiScope::Read->value];
        $errors = null;
        $created = null;

        if ($request->getMethod() === 'POST') {
            $parsed = ApiKeyForm::parse(RequestContext::form($request), $user->preferences->locale);
            if ($parsed instanceof ValidationErrors) {
                $values = RequestContext::formValues($request);
                $errors = $parsed;
            } else {
                $created = $this->keys->create($user, $parsed['name'], $parsed['scope']);
            }
        }

        return $this->render($request, $response, $values, $errors, $created);
    }

    /**
     * @param array<string, string> $values
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $values,
        ?ValidationErrors $errors,
        ?CreatedApiKey $created,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);

        return $this->view->render($request, $response, 'settings/api_keys.twig', [
            'keys' => $this->keys->keysOf($user),
            'scopes' => ApiScope::cases(),
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'created' => $created,
            'api_enabled' => $this->settings->apiEnabled,
            'api_base' => AbsoluteUrl::origin($this->settings->url, $this->settings->basePath)
                . $this->settings->basePath . '/api/v1',
            'openapi_url' => $this->settings->apiEnabled ? $this->urls->route('api.openapi') : null,
        ], $errors === null ? 200 : 422)->withHeader('Cache-Control', 'no-store');
    }
}
