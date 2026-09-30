<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\User\Username;
use Logbook\Service\User\InvitationMailer;
use Logbook\Service\User\UserAdmin;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/users — the users of this install and inviting a new
 * one (spec.md §7.9; admins only). The link is shown once, on the page
 * that answers the invite; when email is configured it can also be sent to
 * an address typed there, which is not stored.
 */
final readonly class UsersAction
{
    public function __construct(
        private UserAdmin $admin,
        private UsersPage $page,
        private InvitationMailer $mailer,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response);
        }

        $user = RequestContext::requireUser($request);
        $input = RequestContext::form($request);
        $validator = new Validator($input, RequestContext::locale($request));
        $username = $validator->string('username', true, Username::MAX_LENGTH);
        if ($username !== null) {
            $normalised = Username::normalise($username);
            if (!Username::isValid($normalised)) {
                $validator->addError('username', 'auth.username_invalid', [
                    'min' => Username::MIN_LENGTH,
                    'max' => Username::MAX_LENGTH,
                ]);
            } elseif (!$this->admin->isUsernameFree($normalised)) {
                $validator->addError('username', 'users.username_taken');
            }
        }
        $displayName = $validator->string('display_name', false, 100);
        $email = $this->page->mailConfigured() ? $validator->string('email', false, 254) : null;
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $validator->addError('email', 'users.email_invalid');
        }
        if ($username === null || !$validator->errors()->isEmpty()) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, null, $values, $validator->errors(), 422);
        }

        $created = $this->admin->invite($user, $username, $displayName ?? $username, $validator->checkbox('is_admin'));
        $emailed = null;
        if ($email !== null) {
            $emailed = $this->mailer->send($created, $email, $user) ? $email : null;
            if ($emailed === null) {
                RequestContext::session($request)->flash('warning', 'users.email_failed');
            }
        }

        return $this->page->render($request, $response, $created, [], null, 200, $emailed);
    }
}
