<?php

declare(strict_types=1);

namespace Logbook\Action\Trip;

use Logbook\Domain\Trip\Journey;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The flashes after a trip is saved: the confirmation, and the warning when
 * it is longer than the mileage log says the vehicle drove that day
 * (spec.md §7.22; never blocking).
 */
final readonly class TripSaved
{
    public function __construct(
        private TripService $trips,
        private DisplayFormatter $formatter,
    ) {
    }

    public function flash(ServerRequestInterface $request, Vehicle $vehicle, TripData $data, string $message): void
    {
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);
        $session->flash('success', $message, ['journey' => Journey::label($data->fromPlace, $data->toPlace, $data->isReturn)]);

        $driven = $this->trips->longerThanDriven($vehicle, $data, $user->preferences->timeZone());
        if ($driven !== null) {
            $session->flash('warning', 'trip.warning.longer_than_driven', [
                'distance' => $this->formatter->distance($data->distanceKm, 1),
                'driven' => $this->formatter->distance($driven, 1),
                'date' => $this->formatter->date($data->travelledOn),
            ]);
        }
    }
}
