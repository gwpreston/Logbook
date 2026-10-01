<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Ai;

use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiConnection;
use Logbook\Service\Ai\AiAdmin;
use Logbook\Service\Ai\ConnectionPreset;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/ai/connections/new and /{connection}/edit — a
 * connection's settings and secrets (spec.md §7.25 *Connections*). A
 * secret is never shown again, and a form re-shown after an error never
 * puts a typed one back.
 */
final readonly class AiConnectionFormAction
{
    /** Fields whose values are secrets: never echoed back. */
    private const array SECRET_FIELDS = ['api_key', 'headers'];

    public function __construct(
        private AiAdmin $admin,
        private AiRoute $route,
        private AppSettings $settings,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $connection = isset($args['connection']) ? $this->route->connection($request, $args) : null;

        if ($request->getMethod() !== 'POST') {
            return $this->render($request, $response, $connection, AiAdmin::values($connection), null);
        }

        $form = $this->admin->parse(RequestContext::form($request), $connection, RequestContext::locale($request));
        if ($form instanceof ValidationErrors) {
            $values = array_diff_key(RequestContext::formValues($request), array_flip(self::SECRET_FIELDS));

            return $this->render($request, $response, $connection, $values, $form, 422);
        }

        $id = $this->admin->save(RequestContext::requireUser($request), $connection, $form);
        RequestContext::session($request)->flash('success', 'ai.connection.saved');

        return $this->redirect->toRoute('settings.ai.connections.show', ['connection' => (string) $id]);
    }

    /**
     * @param array<string, string> $values
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?AiConnection $connection,
        array $values,
        ?ValidationErrors $errors,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'settings/ai/connection_form.twig', [
            'connection' => $connection,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'presets' => ConnectionPreset::cases(),
            // For the JS that fills the adapter and URL from a preset.
            'preset_map' => array_combine(
                array_map(static fn (ConnectionPreset $p): string => $p->value, ConnectionPreset::cases()),
                array_map(
                    static fn (ConnectionPreset $p): array => ['adapter' => $p->adapter()->value, 'url' => $p->url()],
                    ConnectionPreset::cases(),
                ),
            ),
            'gateway' => $connection !== null && ConnectionPreset::isGateway($connection->host()),
            'adapters' => AdapterType::cases(),
            'secrets' => $connection === null ? [] : $this->admin->secretStates($connection),
            'allow_insecure_tls' => $this->settings->ai->allowInsecureTls,
        ], $status);
    }
}
