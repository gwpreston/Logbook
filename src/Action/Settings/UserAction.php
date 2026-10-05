<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Auth\PasswordResets;
use Logbook\Service\User\UserAdmin;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/users/{member}/{action:admin|member|enable|reset} — one
 * change to a user (spec.md §7.9; admins only). *Reset* emails the new
 * one-time link to their address when it can (*Send reset email*, Phase
 * 33.1), and otherwise answers with it, shown once: never both. Refusals
 * (the last admin, oneself) come back as a message. *Revoke access* and
 * *Sign out everywhere* ask first (ConfirmUserAction).
 */
final readonly class UserAction
{
    public function __construct(
        private UserAdmin $admin,
        private UsersPage $page,
        private Redirector $redirect,
        private PasswordResets $resets,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = RequestContext::requireUser($request);
        $user = $this->admin->find((int) ($args['member'] ?? 0)) ?? throw new HttpNotFoundException($request);
        $session = RequestContext::session($request);
        $params = ['name' => $user->displayName];

        $action = $args['action'] ?? '';
        if ($action === 'reset') {
            $address = $this->resets->adminAddress($user);
            $link = $this->admin->resetLink($actor, $user, $address);
            $client = RequestContext::clientAddress($request);
            if ($address !== null && $this->resets->emailAdminLink($actor, $user, $link, $address, $client)) {
                $session->flash('success', 'users.done.reset_emailed', $params + ['address' => $address]);

                return $this->redirect->toRoute('settings.users');
            }
            if ($address !== null) {
                $session->flash('warning', 'users.email_failed');
            }

            return $this->page->render($request, $response, $link);
        }

        $refusal = match ($action) {
            'admin' => $this->admin->setAdmin($user, true),
            'member' => $this->admin->setAdmin($user, false),
            default => null,
        };
        if ($action === 'enable') {
            $this->admin->enable($user);
        }
        if ($refusal !== null) {
            $session->flash('error', 'users.refused.' . $refusal->value, $params);
        } else {
            $session->flash('success', 'users.done.' . $action, $params);
        }

        return $this->redirect->toRoute('settings.users');
    }
}
