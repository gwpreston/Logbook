<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Delivery;

use Logbook\Service\Mail\EmailServerAdmin;
use Logbook\Service\Mail\MailConfig;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/delivery/remove — confirm, then remove the email
 * server and its saved password (spec.md §7.11): email is off.
 */
final readonly class DeliveryRemoveAction
{
    public function __construct(
        private MailConfig $config,
        private EmailServerAdmin $admin,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $server = $this->config->effective();
        if ($server === null) {
            return $this->redirect->toRoute('settings.delivery');
        }
        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'settings/delivery/remove.twig', ['server' => $server]);
        }

        $this->admin->remove(RequestContext::requireUser($request));
        RequestContext::session($request)->flash('success', 'delivery.email.removed');

        return $this->redirect->toRoute('settings.delivery');
    }
}
