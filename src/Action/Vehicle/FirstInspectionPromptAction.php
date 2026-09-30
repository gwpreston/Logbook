<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\FirstInspectionPrompt;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /vehicles/{id}/first-inspection — the overview's one-time *First
 * MOT* card (spec.md §7.1): `choice=set` stores the suggestion, worked out
 * again here (a posted date is never trusted); anything else is *Not
 * needed*. Either settles the card for good. A card that no longer applies
 * (the date was set elsewhere, a certificate was logged) sets nothing.
 */
final readonly class FirstInspectionPromptAction
{
    public function __construct(
        private VehicleService $vehicles,
        private FirstInspectionPrompt $prompt,
        private Redirector $redirect,
        private ClockInterface $clock,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $set = (RequestContext::form($request)['choice'] ?? '') === 'set';

        $suggestion = $set ? $this->prompt->suggestion($user, $vehicle, $today) : null;
        if ($suggestion !== null) {
            $this->vehicles->update($user, $vehicle, $vehicle->data->withFirstInspectionDueOn($suggestion));
            RequestContext::session($request)->flash('success', 'vehicle.first_inspection_prompt.saved', [
                'date' => $this->formatter->date($suggestion),
            ]);
        }
        $this->prompt->settle($vehicle);

        return $this->redirect->toRoute('vehicles.show', ['id' => (string) $vehicle->id]);
    }
}
