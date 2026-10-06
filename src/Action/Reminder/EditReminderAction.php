<?php

declare(strict_types=1);

namespace Logbook\Action\Reminder;

use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Reminder\ManualReminderForm;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /reminders/{reminder}/edit — manual reminders only; the others
 * are changed through their schedule or document.
 */
final readonly class EditReminderAction
{
    public function __construct(
        private ReminderService $reminders,
        private ReminderFormPage $page,
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
        $vehicleIds = array_map(static fn (Vehicle $v): int => $v->id, $this->page->vehicles($user));
        if ($reminder->source !== ReminderSource::Manual || !in_array($reminder->vehicleId, $vehicleIds, true)) {
            throw new HttpNotFoundException($request);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, ManualReminderForm::values($reminder, $user->preferences), $reminder);
        }

        $data = ManualReminderForm::parse(RequestContext::form($request), $user->preferences, $vehicleIds);
        if ($data instanceof ValidationErrors) {
            return $this->page->render($request, $response, RequestContext::formValues($request), $reminder, $data, 422);
        }

        $saved = $this->reminders->updateManual($user, $reminder, $data);
        RequestContext::session($request)->flash('success', 'reminders.updated', ['title' => $saved->title]);

        // Back to the calendar when it was opened from there (a validated `return`).
        return $this->redirect->backOr($request, 'reminders.index');
    }
}
