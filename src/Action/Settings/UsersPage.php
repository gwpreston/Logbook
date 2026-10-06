<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Auth\Oidc\OidcSignIn;
use Logbook\Service\Auth\Oidc\OidcSignOut;
use Logbook\Service\Auth\SignInMethods;
use Logbook\Service\Mail\MailConfig;
use Logbook\Support\Config\AppSettings;
use Logbook\Service\User\CreatedLink;
use Logbook\Service\User\UserAdmin;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders Settings → Users (spec.md §7.9): the users, the open links, the
 * invite form, and a link just made (shown once: never cached).
 */
final readonly class UsersPage
{
    public function __construct(
        private UserAdmin $admin,
        private View $view,
        private SignInMethods $methods,
        private OidcSignIn $oidc,
        private OidcSignOut $signOut,
        private AppSettings $settings,
        private MailConfig $mail,
    ) {
    }

    public function mailConfigured(): bool
    {
        return $this->mail->isConfigured();
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?CreatedLink $created = null,
        array $values = [],
        ?ValidationErrors $errors = null,
        int $status = 200,
        ?string $emailedTo = null,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'settings/users.twig', [
            'users' => $this->admin->users(),
            'links' => $this->admin->openLinks(),
            'created' => $created,
            'emailed_to' => $emailedTo,
            'mail_configured' => $this->mailConfigured(),
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            // Sign-in methods per user and the single sign-on setup (spec.md §7.9 *Admin view*).
            'identities' => $this->methods->identitiesByUser(),
            'local_login' => $this->settings->localLogin,
            'sso' => [
                'configured' => $this->oidc->isConfigured(),
                'name' => $this->settings->oidc->providerName,
                'redirect_uri' => $this->oidc->redirectUri(),
                'logout_uri' => $this->signOut->postLogoutRedirect(),
                'logout' => $this->settings->oidc->logout,
            ],
            'proxy_setup' => [
                'mode' => $this->settings->proxy->mode->value,
                'header' => $this->settings->proxy->header,
                'trusted' => array_map(strval(...), $this->settings->proxy->trusted),
                'link' => $this->settings->proxy->link->value,
            ],
        ], $status)->withHeader('Cache-Control', 'no-store');
    }
}
