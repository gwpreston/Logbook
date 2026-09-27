<?php

declare(strict_types=1);

namespace Logbook\Action\Reminder;

use Logbook\Service\Reminder\CalendarFeed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /calendar/{token}.ics — the owner's reminders as an iCalendar feed
 * for calendar apps (spec.md §7.6). The secret token is the authentication:
 * no session, and an unknown or revoked token is a plain 404.
 */
final readonly class CalendarFeedAction
{
    public function __construct(private CalendarFeed $feed)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->feed->userFor($args['token'] ?? '');
        if ($user === null) {
            throw new HttpNotFoundException($request);
        }

        $response->getBody()->write($this->feed->render($user));

        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="logbook-reminders.ics"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
