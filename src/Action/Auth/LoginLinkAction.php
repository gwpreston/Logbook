<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Domain\User\InvitationKind;
use Logbook\Service\User\InvitationService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /login/link/{token} — a break-glass sign-in link from
 * `php bin/auth.php login-link` (spec.md §7.9). Opening it asks for one
 * click (a link preview or prefetch must not use it up); the POST signs
 * the user in, once, within ten minutes, whatever AUTH_LOCAL_LOGIN says.
 */
final readonly class LoginLinkAction
{
    public function __construct(
        private InvitationService $invitations,
        private View $view,
        private Redirector $redirect,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $args['token'] ?? '';
        $invitation = $this->invitations->open($token);
        if ($invitation === null || $invitation->kind !== InvitationKind::Login) {
            throw new HttpNotFoundException($request);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'auth/login_link.twig', [
                'invitation' => $invitation,
                'token' => $token,
            ])->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        }

        $user = $this->invitations->acceptLogin($invitation);
        if ($user === null) {
            throw new HttpNotFoundException($request);
        }
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $this->logger->notice('"{username}" signed in with a break-glass link from {ip}.', [
            'username' => $user->username,
            'ip' => is_string($ip) ? $ip : 'unknown',
        ]);

        $session = RequestContext::session($request);
        $session->signIn($user->id);
        $session->flash('success', 'auth.login_link_used', ['name' => $user->displayName]);

        return $this->redirect->toRoute('home');
    }
}
