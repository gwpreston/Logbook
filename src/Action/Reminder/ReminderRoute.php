<?php

declare(strict_types=1);

namespace Logbook\Action\Reminder;

use Logbook\Domain\Reminder\Reminder;
use Logbook\Service\Reminder\ReminderNotFound;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /reminders/{reminder} routes.
 */
final class ReminderRoute
{
    /**
     * The signed-in owner's reminder named by the route, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function reminder(ReminderService $reminders, ServerRequestInterface $request, array $args): Reminder
    {
        try {
            return $reminders->get(RequestContext::requireUser($request), (int) ($args['reminder'] ?? 0));
        } catch (ReminderNotFound) {
            throw new HttpNotFoundException($request);
        }
    }
}
