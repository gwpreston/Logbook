<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Notifications;

use Logbook\Service\Notification\Personal\UserChannels;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /settings/notifications/{kind}/switch — switch one of the signed-in
 * user's channels on (`enabled=1`) or off. Email's switch is in their
 * preferences; a personal channel must be saved first. Switching on clears
 * a switch-off after failures (spec.md §7.11).
 */
final readonly class SwitchChannelAction
{
    public function __construct(
        private UserChannels $channels,
        private ReminderSettingsStore $settings,
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
        $kind = $args['kind'] ?? '';
        $enabled = (RequestContext::form($request)['enabled'] ?? null) === '1';

        if ($kind === 'email') {
            $preferences = $this->settings->notificationPreferences($user->id);
            $this->settings->saveNotificationPreferences($user->id, $preferences->withChannel('email', $enabled));
            $label = 'notifications.channel.email';
        } else {
            $sender = $this->channels->kinds()->get($kind);
            if ($sender === null || $this->channels->find($user->id, $kind) === null) {
                throw new HttpNotFoundException($request);
            }
            $this->channels->setEnabled($user, $kind, $enabled);
            $label = $sender->definition()->label;
        }
        $message = $enabled ? 'notifications.switched_on' : 'notifications.switched_off';
        RequestContext::session($request)->flash('success', $message, [
            'channel' => $this->translator->trans($label),
        ]);

        return $this->redirect->to($this->redirect->urlFor('settings.notifications') . '#channel-' . $kind);
    }
}
