<?php

declare(strict_types=1);

namespace Logbook\Action\Compliance;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/documents — the documents tab: current documents with
 * their expiry status (most urgent first), then the ones since replaced.
 */
final readonly class ComplianceListAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ComplianceService $compliance,
        private AttachmentService $attachments,
        private View $view,
        private ClockInterface $clock,
        private ReminderSettingsStore $reminderSettings,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $leadDays = $this->reminderSettings->reminderPreferences($user->id)->documentDays;
        $states = $this->compliance->states($vehicle, LocalTime::today($this->clock, $user->preferences->timeZone()), $leadDays);

        return $this->view->render($request, $response, 'compliance/index.twig', [
            'vehicle' => $vehicle,
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
            'current' => array_values(array_filter($states, static fn (DocumentState $s): bool => $s->status->isCurrent())),
            'replaced' => array_values(array_filter($states, static fn (DocumentState $s): bool => !$s->status->isCurrent())),
            'attachments' => $this->attachments->index($vehicle),
            'types' => ComplianceType::cases(),
            'lead_days' => $leadDays,
        ]);
    }
}
