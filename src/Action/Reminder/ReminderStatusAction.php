<?php

declare(strict_types=1);

namespace Logbook\Action\Reminder;

use Logbook\Service\Reminder\ReminderService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /reminders/{reminder}/{done|dismiss|reopen} — one-click forms on the
 * reminder list (work without JS) and on *Needs attention* (spec.md §7.24),
 * which sends `return` to come back to the page it was on.
 */
final readonly class ReminderStatusAction
{
    public function __construct(
        private ReminderService $reminders,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $reminder = ReminderRoute::reminder($this->reminders, $request, $args);
        if ($this->reminders->vehicleOf($user, $reminder)?->isArchived() !== false) {
            throw new HttpNotFoundException($request);
        }

        $action = $args['action'] ?? '';
        match ($action) {
            'done' => $this->reminders->markDone($reminder),
            'dismiss' => $this->reminders->dismiss($reminder),
            'reopen' => $this->reminders->reopen($user, $reminder),
            default => throw new HttpNotFoundException($request),
        };
        RequestContext::session($request)->flash('success', 'reminders.status_changed.' . $action);

        return $this->redirect->backOr($request, 'reminders.index');
    }
}
