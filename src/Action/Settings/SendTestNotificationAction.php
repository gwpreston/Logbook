<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Notification\NotificationComposer;
use Logbook\Service\Notification\NotificationDispatcher;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/reminders/test — send a test notification through the
 * owner's saved channels and say which worked.
 */
final readonly class SendTestNotificationAction
{
    public function __construct(
        private ReminderSettingsStore $settings,
        private NotificationComposer $composer,
        private NotificationDispatcher $dispatcher,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $preferences = $this->settings->notificationPreferences($user->id);

        $report = $this->dispatcher->dispatch(
            $this->composer->test($user),
            Recipient::of($user, $preferences),
            $preferences,
        );

        $session = RequestContext::session($request);
        if ($report->hadNoChannels()) {
            $session->flash('error', 'reminders.settings.test_no_channels');
        } else {
            foreach ($report->results as $result) {
                if ($result->delivered) {
                    $session->flash('success', 'reminders.settings.test_sent', ['channel' => $result->channel]);
                } else {
                    $session->flash('error', 'reminders.settings.test_failed', ['channel' => $result->channel]);
                }
            }
        }

        return $this->redirect->toRoute('settings.reminders');
    }
}
