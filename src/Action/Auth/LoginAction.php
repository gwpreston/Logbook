<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Service\Auth\AuthService;
use Logbook\Service\Auth\PasswordResets;
use Logbook\Service\Demo\DemoMode;
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
 * tool such as fail2ban can watch the log. With single sign-on configured
 * the page offers *Sign in with {name}*; with AUTH_LOCAL_LOGIN=false it
 * offers only that, and a password POST is refused (spec.md §7.9).
 */
final readonly class LoginAction
{
    public function __construct(
        private AuthService $auth,
        private View $view,
        private Redirector $redirect,
        private AppSettings $settings,
        private LoggerInterface $logger,
        private PasswordResets $resets,
        private DemoMode $demo,
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
            // A visitor whose session the demo's reset ended (spec.md §7.36).
            $reset = $this->demo->isActive() && ($request->getQueryParams()['demo'] ?? null) === 'reset';

            return $this->view->render($request, $response, 'auth/login.twig', $this->page($next) + [
                'notice' => $reset ? 'demo.reset_notice' : null,
            ]);
        }
        if (!$this->settings->localLogin) {
            $this->logger->notice('Password sign-in refused (AUTH_LOCAL_LOGIN=false) from {ip}', [
                'ip' => self::stringParam($request->getServerParams()['REMOTE_ADDR'] ?? null) ?? 'unknown',
            ]);

            return $this->view->render($request, $response, 'auth/login.twig', $this->page($next) + [
                'error' => 'auth.local_login_off',
            ], 403);
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
                'username' => $username,
                'error' => 'auth.invalid_credentials',
            ] + $this->page($next), 422);
        }

        RequestContext::session($request)->signIn($user->id);

        return $next !== null ? $this->redirect->to($next) : $this->redirect->toRoute('home');
    }

    /**
     * @return array<string, mixed>
     */
    private function page(?string $next): array
    {
        return [
            'next' => $next,
            'username' => '',
            'local_login' => $this->settings->localLogin,
            // The demo has one account and no mail, and no other way in (spec.md §7.36).
            'forgot_password' => !$this->demo->isActive() && $this->resets->isAvailable(),
            'sso' => !$this->demo->isActive() && $this->settings->oidc->isConfigured()
                ? ['name' => $this->settings->oidc->providerName]
                : null,
        ];
    }

    private static function stringParam(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
