<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /logout — end the session (POST-only, CSRF-protected, so a link on
 * another site cannot sign you out).
 */
final readonly class LogoutAction
{
    public function __construct(private Redirector $redirect)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = RequestContext::session($request);
        $session->destroy();
        $session->flash('success', 'auth.signed_out');

        return $this->redirect->toRoute('login');
    }
}
