<?php

declare(strict_types=1);

namespace Logbook\Action\Reminder;

use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /reminders/{reminder}/delete — confirm (works without JS), then
 * delete a manual reminder.
 */
final readonly class DeleteReminderAction
{
    public function __construct(
        private ReminderService $reminders,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $reminder = ReminderRoute::reminder($this->reminders, $request, $args);
        if ($reminder->source !== ReminderSource::Manual) {
            throw new HttpNotFoundException($request);
        }
        $params = ['title' => $reminder->title];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'nav' => 'reminders',
                'back_label' => 'reminders.title',
                'title' => 'reminders.delete_title',
                'body' => 'reminders.delete_body',
                'params' => $params,
                'action' => ['reminders.delete', ['reminder' => $reminder->id]],
                'cancel' => ['reminders.index', []],
            ]);
        }

        $this->reminders->deleteManual($reminder);
        RequestContext::session($request)->flash('success', 'reminders.deleted', $params);

        return $this->redirect->toRoute('reminders.index');
    }
}
