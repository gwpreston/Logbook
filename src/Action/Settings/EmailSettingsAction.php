<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\User\EmailAddress;
use Logbook\Service\Auth\AuthService;
use Logbook\Service\User\EmailAddresses;
use Logbook\Service\User\EmailChange;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/email, /settings/email/resend and /settings/email/cancel
 * (spec.md §7.9 *Email addresses*): changing or removing the address needs
 * the current password when the user has one; a new address waits for its
 * link.
 */
final readonly class EmailSettingsAction
{
    public function __construct(
        private EmailAddresses $emails,
        private AuthService $auth,
        private ProfilePage $page,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);

        if (($args['action'] ?? '') === 'resend') {
            $sent = $this->emails->sendConfirmation($user, $user);
            $session->flash($sent ? 'success' : 'error', $sent ? 'account.email.sent' : 'account.email.not_sent', [
                'address' => $user->emailPending ?? '',
            ]);

            return $this->redirect->toRoute('profile');
        }
        if (($args['action'] ?? '') === 'cancel') {
            $this->emails->cancel($user);
            $session->flash('success', 'account.email.cancelled');

            return $this->redirect->toRoute('profile');
        }

        $input = RequestContext::form($request);
        $validator = new Validator($input, RequestContext::locale($request));
        $typed = $validator->string('email', false, EmailAddress::MAX_LENGTH);
        if ($typed !== null && EmailAddress::parse($typed) === null) {
            $validator->addError('email', 'validation.email');
        }
        $password = is_string($input['email_password'] ?? null) ? $input['email_password'] : '';
        if ($user->hasPassword() && !$this->auth->verifyPassword($user, $password)) {
            $validator->addError('email_password', 'auth.current_password_wrong');
        }
        if (!$validator->errors()->isEmpty()) {
            return $this->page->render($request, $response, status: 422, emailErrors: $validator->errors(), emailValues: [
                'email' => $typed ?? '',
            ]);
        }

        $change = $this->emails->change($user, $typed);
        $address = EmailAddress::parse($typed) ?? '';
        $session->flash($change === EmailChange::PendingNotSent ? 'error' : 'success', 'account.email.' . $change->value, [
            'address' => $address,
        ]);

        return $this->redirect->toRoute('profile');
    }
}
