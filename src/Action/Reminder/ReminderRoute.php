<?php

declare(strict_types=1);

namespace Logbook\Action\Reminder;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Service\Reminder\ReminderNotFound;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Support\Http\AccessDeniedException;
use Logbook\Support\Http\RequestContext;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /reminders/{reminder} routes. Like a
 * vehicle route, each declares the ability it needs on the reminder's
 * vehicle (the route argument `ability`; spec.md §5 *Access policy*).
 */
final class ReminderRoute
{
    /**
     * The reminder named by the route: a 404 unless the user can see its
     * vehicle, a 403 without the route's ability on it.
     *
     * @param array<string, string> $args
     */
    public static function reminder(ReminderService $reminders, ServerRequestInterface $request, array $args): Reminder
    {
        $ability = VehicleAbility::tryFrom($args[VehicleAccessMiddleware::ABILITY] ?? '')
            ?? throw new LogicException('A reminder route declares no vehicle ability.');
        $user = RequestContext::requireUser($request);
        try {
            $reminder = $reminders->get($user, (int) ($args['reminder'] ?? 0));
        } catch (ReminderNotFound) {
            throw new HttpNotFoundException($request);
        }
        if (!$reminders->allows($user, $ability, $reminder)) {
            throw new AccessDeniedException($request);
        }

        return $reminder;
    }
}
