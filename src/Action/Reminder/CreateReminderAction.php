<?php

declare(strict_types=1);

namespace Logbook\Action\Reminder;

use Logbook\Action\Ask\DraftPrefill;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Reminder\ManualReminderForm;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /reminders/new — add a manual reminder. `?vehicle=3` pre-selects
 * the vehicle.
 */
final readonly class CreateReminderAction
{
    public function __construct(
        private DraftPrefill $prefill,
        private ReminderService $reminders,
        private ReminderSettingsStore $settings,
        private ReminderFormPage $page,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicleIds = array_map(static fn (Vehicle $v): int => $v->id, $this->page->vehicles($user));

        if ($request->getMethod() !== 'POST') {
            $wanted = $request->getQueryParams()['vehicle'] ?? null;
            $vehicle = is_string($wanted) && in_array((int) $wanted, $vehicleIds, true)
                ? (int) $wanted
                : ($vehicleIds[0] ?? null);
            $defaults = ManualReminderForm::defaults($this->settings->reminderPreferences($user->id), $vehicle);
            // A suggested title, as an incident's *Add reminder* gives (spec.md §7.29).
            $title = $request->getQueryParams()['title'] ?? null;
            if (is_string($title) && trim($title) !== '' && mb_strlen($title) <= ManualReminderForm::TITLE_MAX) {
                $defaults['title'] = trim($title);
            }
            $defaults = $this->prefill->values($request, DraftKind::Reminder, null, $defaults);

            return $this->page->render($request, $response, $defaults);
        }

        $data = ManualReminderForm::parse(RequestContext::form($request), $user->preferences, $vehicleIds);
        if ($data instanceof ValidationErrors) {
            return $this->page->render($request, $response, RequestContext::formValues($request), null, $data, 422);
        }

        $reminder = $this->reminders->createManual($user, $data);
        $this->prefill->saved($request);
        RequestContext::session($request)->flash('success', 'reminders.created', ['title' => $reminder->title]);

        return $this->redirect->toRoute('reminders.index');
    }
}
