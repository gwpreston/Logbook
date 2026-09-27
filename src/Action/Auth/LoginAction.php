<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Service\Auth\AuthService;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Security\SafeRedirect;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * GET|POST /login — sign in, then return to the page originally requested.
 *
 * Failed attempts are logged at notice level with the client address, so a
 * tool such as fail2ban can watch the log.
 */
final readonly class LoginAction
{
    public function __construct(
        private AuthService $auth,
        private View $view,
        private Redirector $redirect,
        private AppSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->auth->setupRequired()) {
            return $this->redirect->toRoute('setup');
        }

        $input = RequestContext::form($request);
        $next = SafeRedirect::localPath(
            self::stringParam($input['next'] ?? $request->getQueryParams()['next'] ?? null),
            $this->settings->basePath,
        );

        if (RequestContext::user($request) !== null) {
            return $next !== null ? $this->redirect->to($next) : $this->redirect->toRoute('home');
        }

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'auth/login.twig', ['next' => $next, 'username' => '']);
        }

        $username = trim(self::stringParam($input['username'] ?? null) ?? '');
        $password = self::stringParam($input['password'] ?? null) ?? '';
        $user = $username === '' || $password === '' ? null : $this->auth->authenticate($username, $password);

        if ($user === null) {
            $this->logger->notice('Failed sign-in for "{username}" from {ip}', [
                'username' => mb_substr($username, 0, 64),
                'ip' => self::stringParam($request->getServerParams()['REMOTE_ADDR'] ?? null) ?? 'unknown',
            ]);

            return $this->view->render($request, $response, 'auth/login.twig', [
                'next' => $next,
                'username' => $username,
                'error' => 'auth.invalid_credentials',
            ], 422);
        }

        RequestContext::session($request)->signIn($user->id);

        return $next !== null ? $this->redirect->to($next) : $this->redirect->toRoute('home');
    }

    private static function stringParam(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
