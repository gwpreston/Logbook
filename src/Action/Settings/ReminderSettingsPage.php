<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Notification\ChannelRegistry;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Reminder\CalendarFeed;
use Logbook\Service\Reminder\ReminderSettingsForm;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders Settings → Reminders: lead times, notification channels, digest
 * and the calendar feed.
 */
final readonly class ReminderSettingsPage
{
    /** Session key holding a just-issued feed URL until it has been shown once. */
    public const string NEW_FEED_URL = 'calendar_feed_url';

    public function __construct(
        private View $view,
        private ReminderSettingsStore $settings,
        private ChannelRegistry $channels,
        private CalendarFeed $feed,
    ) {
    }

    /**
     * @param array<string, string>|null $values submitted values; null = the saved ones
     * @param list<string>|null $enabled submitted channel keys; null = the saved ones
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $values = null,
        ?array $enabled = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);
        $notifications = $this->settings->notificationPreferences($user->id);

        $recipient = Recipient::of($user, $notifications);
        $channels = array_map(static fn (NotificationChannel $c): array => [
            'key' => $c->key(),
            'label' => $c->label(),
            'configured' => $c->isConfigured() || $c->reaches($recipient),
            // Configured here but not for them: a member needs their own address, topic or token (Phase 19).
            'reaches' => $c->reaches($recipient),
            'enabled' => $enabled === null ? $notifications->isEnabled($c->key()) : in_array($c->key(), $enabled, true),
        ], $this->channels->all());

        $session = RequestContext::session($request);
        $newFeed = $session->get(self::NEW_FEED_URL);
        $session->remove(self::NEW_FEED_URL);

        return $this->view->render($request, $response, 'settings/reminders.twig', [
            'values' => $values ?? ReminderSettingsForm::values(
                $this->settings->reminderPreferences($user->id),
                $notifications,
                $user->preferences,
            ),
            'digest' => $values === null ? $notifications->digest : ($values['digest'] ?? '') !== '',
            'errors' => $errors?->all() ?? [],
            'channels' => $channels,
            'any_active' => $this->channels->active($notifications, $recipient) !== [],
            'is_admin' => $user->isAdmin,
            'feed_enabled' => $this->feed->isEnabled($user),
            'new_feed' => is_array($newFeed) ? $newFeed : null,
        ], $status);
    }
}
