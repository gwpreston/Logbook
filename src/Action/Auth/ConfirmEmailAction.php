<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Domain\User\InvitationKind;
use Logbook\Service\User\EmailAddresses;
use Logbook\Service\User\InvitationService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /confirm-email/{token} (spec.md §7.9 *Email addresses*): the
 * GET shows a *Confirm* button and spends nothing, so a mail scanner's
 * preview cannot confirm; the POST makes the pending address the user's,
 * signed in or not. A used, expired, replaced or unknown link is a 404.
 */
final readonly class ConfirmEmailAction
{
    public function __construct(
        private InvitationService $invitations,
        private EmailAddresses $emails,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $args['token'] ?? '';
        $invitation = $this->invitations->open($token);
        if ($invitation === null || $invitation->kind !== InvitationKind::Email || $invitation->email === null) {
            throw new HttpNotFoundException($request);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'auth/confirm_email.twig', [
                'token' => $token,
                'address' => $invitation->email,
                'username' => $invitation->username,
            ])->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        }

        $user = $this->emails->confirm($invitation);
        if ($user === null) {
            throw new HttpNotFoundException($request);
        }
        $session = RequestContext::session($request);
        $session->flash('success', 'account.email.confirmed', ['address' => $invitation->email]);
        $signedIn = RequestContext::user($request);

        return $this->redirect->toRoute($signedIn !== null && $signedIn->id === $user->id ? 'profile' : 'login');
    }
}
