<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\User\EmailAddress;
use Logbook\Domain\User\Username;
use Logbook\Service\Auth\PasswordResets;
use Logbook\Service\User\UserAdmin;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/users/add — *Add user* (spec.md §7.9 *Admin
 * controls*; admins only): the account exists at once, without a password,
 * and a 7-day set-password link goes to the address typed. Needs email:
 * without it the page says to use *Invite* instead.
 */
final readonly class AddUserAction
{
    public function __construct(
        private UserAdmin $admin,
        private PasswordResets $resets,
        private View $view,
        private Redirector $redirect,
        private AppSettings $settings,
        private UsersPage $users,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST' || !$this->users->mailConfigured()) {
            return $this->form($request, $response, [], null, $request->getMethod() === 'POST' ? 422 : 200);
        }

        $actor = RequestContext::requireUser($request);
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
        $email = $validator->string('email', true, EmailAddress::MAX_LENGTH);
        if ($email !== null && EmailAddress::parse($email) === null) {
            $validator->addError('email', 'users.email_invalid');
        }
        if ($username === null || $email === null || !$validator->errors()->isEmpty()) {
            return $this->form($request, $response, RequestContext::formValues($request), $validator->errors(), 422);
        }

        $preset = UnitPreset::Metric;
        $user = $this->admin->addUser(
            $actor,
            $username,
            $displayName ?? Username::normalise($username),
            $email,
            $validator->checkbox('is_admin'),
            new DisplayPreferences(
                $this->settings->locale,
                $this->settings->timezone,
                $preset->distance(),
                $preset->volume(),
                $preset->consumption(),
                $this->settings->currency,
                depthUnit: $preset->depth(),
            ),
        );
        $address = $this->resets->adminAddress($user) ?? EmailAddress::normalise($email);
        $link = $this->admin->resetLink($actor, $user, $address);
        $session = RequestContext::session($request);
        $params = ['name' => $user->displayName, 'address' => $address];
        if ($this->resets->emailAdminLink($actor, $user, $link, $address, RequestContext::clientAddress($request))) {
            $session->flash('success', 'users.done.added', $params);

            return $this->redirect->toRoute('settings.users');
        }
        $session->flash('warning', 'users.email_failed');

        return $this->users->render($request, $response, $link);
    }

    /**
     * @param array<string, string> $values
     */
    private function form(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $values,
        ?ValidationErrors $errors,
        int $status,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'settings/user_add.twig', [
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'mail_configured' => $this->users->mailConfigured(),
        ], $status);
    }
}
