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
 * POST /settings/users/links/{invitation}/revoke — an open invitation or
 * reset link stops working (spec.md §7.9; admins only).
 */
final readonly class RevokeInvitationAction
{
    public function __construct(
        private UserAdmin $admin,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->admin->revoke((int) ($args['invitation'] ?? 0))) {
            throw new HttpNotFoundException($request);
        }
        RequestContext::session($request)->flash('success', 'users.done.revoke');

        return $this->redirect->toRoute('settings.users');
    }
}
