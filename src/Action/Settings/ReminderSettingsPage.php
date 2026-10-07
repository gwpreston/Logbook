<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Attention\AttentionSettingsStore;
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
 * Renders Settings → Reminders: lead times, the *Needs attention*
 * thresholds, digest, the test notification and the calendar feed, and
 * one line saying where notifications go (spec.md §8, Phase 36.2).
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
        private AttentionSettingsStore $attention,
    ) {
    }

    /**
     * @param array<string, string>|null $values submitted values; null = the saved ones
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $values = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);
        $notifications = $this->settings->notificationPreferences($user->id);

        // "Sent to: Email, ntfy" (spec.md §8): every channel that would be used now.
        $active = $this->channels->active($notifications, Recipient::of($user));
        $sentTo = array_values(array_unique(array_map(static fn (NotificationChannel $c): string => $c->label(), $active)));

        $session = RequestContext::session($request);
        $newFeed = $session->get(self::NEW_FEED_URL);
        $session->remove(self::NEW_FEED_URL);

        return $this->view->render($request, $response, 'settings/reminders.twig', [
            'values' => $values ?? ReminderSettingsForm::values(
                $this->settings->reminderPreferences($user->id),
                $user->preferences,
                $this->attention->thresholds($user->id),
            ),
            'digest' => $values === null ? $notifications->digest : ($values['digest'] ?? '') !== '',
            'errors' => $errors?->all() ?? [],
            'sent_to' => $sentTo,
            'any_active' => $active !== [],
            'feed_enabled' => $this->feed->isEnabled($user),
            'new_feed' => is_array($newFeed) ? $newFeed : null,
        ], $status);
    }
}
