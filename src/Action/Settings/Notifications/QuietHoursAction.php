<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Notifications;

use Logbook\Service\Notification\QuietHours;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/notifications/quiet — the signed-in user's quiet hours
 * (spec.md §7.11 *Quiet hours*, Phase 36.4, #250): off, or a start and an
 * end time in their time zone that differ.
 */
final readonly class QuietHoursAction
{
    private const string TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private NotificationsPage $page,
        private ReminderSettingsStore $settings,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $input = RequestContext::formValues($request);
        $on = ($input['quiet_on'] ?? '') === '1';
        $start = trim($input['quiet_start'] ?? '');
        $end = trim($input['quiet_end'] ?? '');

        $quiet = null;
        if ($on) {
            $errors = [];
            foreach (['quiet_start' => $start, 'quiet_end' => $end] as $field => $value) {
                if (preg_match(self::TIME, $value) !== 1) {
                    $errors[$field] = ['key' => 'notifications.quiet.error_time', 'params' => []];
                }
            }
            if ($errors === [] && $start === $end) {
                $errors['quiet_end'] = ['key' => 'notifications.quiet.error_same', 'params' => []];
            }
            $quiet = QuietHours::of($start, $end);
            if ($errors !== [] || $quiet === null) {
                return $this->page->render($request, $response, 'quiet', null, null, 422, null, null, $errors, [
                    'quiet_on' => '1',
                    'quiet_start' => $start,
                    'quiet_end' => $end,
                ]);
            }
        }

        $preferences = $this->settings->notificationPreferences($user->id);
        $this->settings->saveNotificationPreferences($user->id, $preferences->withQuiet($quiet));
        $message = $quiet === null ? 'notifications.quiet.saved_off' : 'notifications.quiet.saved';
        RequestContext::session($request)->flash('success', $message, [
            'start' => $start,
            'end' => $end,
        ]);

        return $this->redirect->to($this->redirect->urlFor('settings.notifications') . '#quiet-hours');
    }
}
