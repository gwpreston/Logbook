<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Reminder\CalendarFeed;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;

/**
 * POST /settings/reminders/calendar — turn the calendar feed on (or give it
 * a new URL: `feed=issue`) or off (`feed=disable`). A new URL is shown once,
 * on the page the redirect lands on.
 */
final readonly class CalendarFeedSettingsAction
{
    public function __construct(
        private CalendarFeed $feed,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);

        switch (RequestContext::form($request)['feed'] ?? null) {
            case 'issue':
                $session->set(ReminderSettingsPage::NEW_FEED_URL, $this->feed->urls($this->feed->issueToken($user)));
                $session->flash('success', 'reminders.calendar.issued');
                break;
            case 'disable':
                $this->feed->disable($user);
                $session->flash('success', 'reminders.calendar.disabled');
                break;
            default:
                throw new HttpBadRequestException($request);
        }

        return $this->redirect->toRoute('settings.reminders');
    }
}
