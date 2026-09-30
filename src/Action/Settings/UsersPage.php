<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Notification\Channel\EmailConfig;
use Logbook\Service\User\CreatedLink;
use Logbook\Service\User\UserAdmin;
use Logbook\Support\Config\Env;
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
    private bool $mailConfigured;

    public function __construct(
        private UserAdmin $admin,
        private View $view,
        Env $env,
    ) {
        $this->mailConfigured = EmailConfig::fromEnv($env)->isConfigured();
    }

    public function mailConfigured(): bool
    {
        return $this->mailConfigured;
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
            'mail_configured' => $this->mailConfigured,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
        ], $status)->withHeader('Cache-Control', 'no-store');
    }
}
