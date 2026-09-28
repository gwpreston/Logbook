<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use Logbook\Middleware\CsrfMiddleware;
use Logbook\Middleware\SessionMiddleware;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * Renders a Twig template into a PSR-7 response.
 *
 * Every page also receives the request-derived variables the layout needs:
 * `user` (null when signed out), `csrf` (hidden-field names and values; see
 * the ui.csrf() macro), `flashes` (one-off messages, consumed here) and
 * `current_path` (for "return to this page" forms).
 */
final readonly class View
{
    /** Sent by js/app.js when it loads a page into the modal dialog (spec.md §5). */
    public const string MODAL_HEADER = 'X-Logbook-Modal';

    public function __construct(private Environment $twig)
    {
    }

    public static function isModal(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine(self::MODAL_HEADER) === '1';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $context = [],
        int $status = 200,
    ): ResponseInterface {
        $response->getBody()->write($this->twig->render($template, $context + $this->requestContext($request)));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withAddedHeader('Vary', self::MODAL_HEADER);
    }

    /**
     * Render without a request (e.g. error pages): a signed-out shell.
     *
     * @param array<string, mixed> $context
     */
    public function fetch(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context + self::emptyRequestContext());
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContext(ServerRequestInterface $request): array
    {
        $name = $request->getAttribute(CsrfMiddleware::PREFIX . '_name');
        $value = $request->getAttribute(CsrfMiddleware::PREFIX . '_value');
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        $uri = $request->getUri();
        $modal = self::isModal($request);

        return [
            'modal' => $modal,
            'user' => RequestContext::user($request),
            'csrf' => is_string($name) && is_string($value) ? [
                'name_key' => CsrfMiddleware::PREFIX . '_name',
                'value_key' => CsrfMiddleware::PREFIX . '_value',
                'name' => $name,
                'value' => $value,
            ] : null,
            // A form loaded into the modal leaves messages for the page behind it.
            'flashes' => $session instanceof Session && !$modal ? $session->takeFlashes() : [],
            // Only pages reached by GET can be returned to (a form re-shown after
            // a failed POST lives at a POST-only address).
            'current_path' => $request->getMethod() === 'GET'
                ? $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '')
                : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyRequestContext(): array
    {
        return ['modal' => false, 'user' => null, 'csrf' => null, 'flashes' => [], 'current_path' => ''];
    }
}
