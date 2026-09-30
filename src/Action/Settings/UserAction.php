<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\User\UserAdmin;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/users/{member}/{action:admin|member|disable|enable|reset} —
 * one change to a user (spec.md §7.9; admins only). *Reset* answers with the
 * new one-time link, shown once. Refusals (the last admin, oneself) come
 * back as a message.
 */
final readonly class UserAction
{
    public function __construct(
        private UserAdmin $admin,
        private UsersPage $page,
        private Redirector $redirect,
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
            return $this->page->render($request, $response, $this->admin->resetLink($actor, $user));
        }

        $refusal = match ($action) {
            'admin' => $this->admin->setAdmin($user, true),
            'member' => $this->admin->setAdmin($user, false),
            'disable' => $this->admin->disable($actor, $user),
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
