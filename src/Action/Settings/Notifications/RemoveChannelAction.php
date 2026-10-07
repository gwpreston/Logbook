<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Notifications;

use Logbook\Service\Notification\Personal\UserChannels;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET|POST /settings/notifications/{kind}/remove — confirm, then remove one
 * of the signed-in user's channels and its saved secrets.
 */
final readonly class RemoveChannelAction
{
    public function __construct(
        private UserChannels $channels,
        private View $view,
        private Redirector $redirect,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $sender = $this->channels->kinds()->get($args['kind'] ?? '');
        if ($sender === null || $this->channels->find($user->id, $sender->definition()->key) === null) {
            throw new HttpNotFoundException($request);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'settings/notifications/remove.twig', [
                'definition' => $sender->definition(),
            ]);
        }

        $this->channels->remove($user, $sender);
        RequestContext::session($request)->flash('success', 'notifications.removed', [
            'channel' => $this->translator->trans($sender->definition()->label),
        ]);

        return $this->redirect->toRoute('settings.notifications');
    }
}
