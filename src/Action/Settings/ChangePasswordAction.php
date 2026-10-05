<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Auth\AuthService;
use Logbook\Service\Auth\PasswordChangeForm;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/password — requires the current password; signs out every
 * other session and moves this one to a new id.
 */
final readonly class ChangePasswordAction
{
    public function __construct(
        private AuthService $auth,
        private ProfilePage $page,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);

        $newPassword = PasswordChangeForm::parse(
            RequestContext::form($request),
            RequestContext::locale($request),
            $user,
            $this->auth,
        );
        if ($newPassword instanceof ValidationErrors) {
            return $this->page->render($request, $response, 422, passwordErrors: $newPassword);
        }

        $this->auth->changePassword($user, $newPassword);

        $session = RequestContext::session($request);
        $session->regenerate();
        $session->flash('success', 'settings.password_changed');

        return $this->redirect->toRoute('profile');
    }
}
