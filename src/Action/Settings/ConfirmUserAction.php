<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\User\UserAdmin;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/users/{member}/{action:disable|sign-out} (spec.md
 * §7.9 *Admin controls*; admins only): *Revoke access* (the existing
 * *Disable*, #158) and *Sign out everywhere*, each after a confirmation
 * page. Signing oneself out everywhere ends this session too.
 */
final readonly class ConfirmUserAction
{
    public function __construct(
        private UserAdmin $admin,
        private View $view,
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
        $action = $args['action'] ?? '';
        $params = ['name' => $user->displayName];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'settings/user_confirm.twig', [
                'target' => $user,
                'action' => $action,
                'self' => $actor->id === $user->id,
            ]);
        }

        $session = RequestContext::session($request);
        if ($action === 'sign-out') {
            $this->admin->signOutEverywhere($user);
            if ($actor->id === $user->id) {
                // This session's row is gone; end it here too, so nothing writes it back.
                $session->destroy();

                return $this->redirect->toRoute('login');
            }
            $session->flash('success', 'users.done.sign_out', $params);

            return $this->redirect->toRoute('settings.users');
        }

        $refusal = $this->admin->disable($actor, $user);
        if ($refusal !== null) {
            $session->flash('error', 'users.refused.' . $refusal->value, $params);
        } else {
            $session->flash('success', 'users.done.disable', $params);
        }

        return $this->redirect->toRoute('settings.users');
    }
}
