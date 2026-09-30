<?php

declare(strict_types=1);

namespace Logbook\Action\Trip;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Trip\SavedJourneyService;
use Logbook\Support\I18n\Region;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the add/edit trip form (spec.md §7.22), with the user's saved
 * journeys for the *Saved journey* select.
 */
final readonly class TripFormPage
{
    public function __construct(
        private View $view,
        private AttachmentUpload $upload,
        private SavedJourneyService $journeys,
    ) {
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        User $user,
        Vehicle $vehicle,
        array $values,
        ?Trip $trip = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $unit = $user->preferences->distanceUnit;
        $journeys = [];
        foreach ($this->journeys->forUser($user) as $journey) {
            $data = $journey->data;
            $journeys[] = [
                'id' => $journey->id,
                'label' => $journey->journey(),
                'from' => $data->fromPlace,
                'to' => $data->toPlace,
                'distance' => Decimal::trim($unit->fromKmDecimal($data->distanceKm, 3)),
                'return' => $data->isReturnDefault,
                'business' => $data->isBusinessDefault,
                'purpose' => $data->purposeDefault ?? '',
            ];
        }
        $locale = $user->preferences->locale;

        return $this->view->render($request, $response, 'trips/form.twig', [
            'vehicle' => $vehicle,
            'trip' => $trip,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'journeys' => $journeys,
            'unit' => $unit->value,
            // The commute hint (spec.md §7.22): GB wording, and German.
            'commute_hint' => Region::of($locale) === 'GB' || str_starts_with($locale, 'de'),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Trip, $trip?->id), $status);
    }
}
