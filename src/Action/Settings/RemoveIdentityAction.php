<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Auth\SignInMethods;
use Logbook\Service\User\UserAdmin;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/users/{member}/identities/{identity}/remove — an admin
 * removes a user's provider account (spec.md §7.9 *Admin view*); refused,
 * like *Unlink*, while it is their only way in.
 */
final readonly class RemoveIdentityAction
{
    public function __construct(
        private UserAdmin $admin,
        private SignInMethods $methods,
        private Redirector $redirect,
        private AppSettings $settings,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = RequestContext::requireUser($request);
        $user = $this->admin->find((int) ($args['member'] ?? 0)) ?? throw new HttpNotFoundException($request);
        $identity = $this->methods->find((int) ($args['identity'] ?? 0));
        $provider = $identity === null ? $this->settings->oidc->providerName : $this->methods->providerName($identity);
        $done = $this->methods->remove($user, (int) ($args['identity'] ?? 0), $actor);
        RequestContext::session($request)->flash(
            $done ? 'success' : 'error',
            $done ? 'users.identity_removed' : 'users.identity_refused',
            ['name' => $user->displayName, 'provider' => $provider],
        );

        return $this->redirect->toRoute('settings.users');
    }
}
