<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\User\UserIdentity;
use Logbook\Service\Auth\SignInMethods;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/sso/{identity}/unlink — the signed-in user removes their
 * own provider account (spec.md §7.9 *Linking*); refused while it is their
 * only way in.
 */
final readonly class OidcUnlinkAction
{
    public function __construct(
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
        $user = RequestContext::requireUser($request);
        $id = (int) ($args['identity'] ?? 0);
        $proxy = $this->methods->find($id)?->provider === UserIdentity::PROXY;
        $done = $this->methods->remove($user, $id);
        RequestContext::session($request)->flash(
            $done ? 'success' : 'error',
            $done ? ($proxy ? 'proxy.unlinked' : 'sso.unlinked') : 'sso.unlink_refused',
            ['name' => $this->settings->oidc->providerName],
        );

        return $this->redirect->toRoute('settings');
    }
}
