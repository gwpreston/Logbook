<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Attention\AttentionSettingsStore;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Reminder\ReminderSettingsForm;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/reminders — lead times, the *Needs attention*
 * thresholds (core, spec.md §7.24) and the monthly digest. Where
 * notifications go is on Settings → Account → Notifications (Phase 36.2).
 * With the reminders module off only the lead times and thresholds are
 * shown and saved (the lead times drive the vehicle tabs' badges); the
 * digest choice is kept as it was.
 */
final readonly class ReminderSettingsAction
{
    public function __construct(
        private ReminderSettingsPage $page,
        private ReminderSettingsStore $settings,
        private Redirector $redirect,
        private FeatureToggles $features,
        private AttentionSettingsStore $attention,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response);
        }

        $user = RequestContext::requireUser($request);
        $input = RequestContext::form($request);
        $parsed = ReminderSettingsForm::parse($input, $user->preferences);
        if ($parsed instanceof ValidationErrors) {
            return $this->page->render($request, $response, RequestContext::formValues($request), $parsed, 422);
        }

        [$reminders, $digest, $attention] = $parsed;
        $this->settings->saveReminderPreferences($user->id, $reminders);
        $this->attention->saveThresholds($user->id, $attention);
        if ($this->features->isEnabled(Feature::Reminders)) {
            $notifications = $this->settings->notificationPreferences($user->id);
            $this->settings->saveNotificationPreferences($user->id, $notifications->withDigest($digest));
        }
        RequestContext::session($request)->flash('success', 'reminders.settings.saved');

        return $this->redirect->toRoute('settings.reminders');
    }
}
