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
 * GET|POST /settings/users/{member}/delete — confirm, then delete a user
 * (spec.md §7.9; admins only). While they own vehicles the page lists them
 * with their *Transfer* instead, and the POST is refused.
 */
final readonly class DeleteUserAction
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

        if ($request->getMethod() === 'POST') {
            $refusal = $this->admin->delete($actor, $user);
            $session = RequestContext::session($request);
            if ($refusal === null) {
                $session->flash('success', 'users.done.delete', ['name' => $user->displayName]);

                return $this->redirect->toRoute('settings.users');
            }
            $session->flash('error', 'users.refused.' . $refusal->value, ['name' => $user->displayName]);

            return $this->redirect->toRoute('settings.users.delete', ['member' => (string) $user->id]);
        }

        return $this->view->render($request, $response, 'settings/user_delete.twig', [
            'target' => $user,
            'vehicles' => $this->admin->ownedVehicles($user),
            'self' => $actor->id === $user->id,
        ]);
    }
}
