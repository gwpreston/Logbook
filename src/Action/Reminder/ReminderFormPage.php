<?php

declare(strict_types=1);

namespace Logbook\Action\Reminder;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the add/edit manual reminder form (one template for both).
 */
final readonly class ReminderFormPage
{
    public function __construct(
        private View $view,
        private VehicleService $vehicles,
    ) {
    }

    /**
     * The active vehicles a reminder can be for: those the user may manage.
     *
     * @return list<Vehicle>
     */
    public function vehicles(User $user): array
    {
        return $this->vehicles->listWith($user, VehicleAbility::Manage);
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $values,
        ?Reminder $reminder = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $options = [];
        foreach ($this->vehicles(RequestContext::requireUser($request)) as $vehicle) {
            $options[] = ['value' => (string) $vehicle->id, 'label' => $vehicle->name()];
        }

        return $this->view->render($request, $response, 'reminders/form.twig', [
            'reminder' => $reminder,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'vehicle_options' => $options,
        ], $status);
    }
}
