<?php

declare(strict_types=1);

namespace Logbook\Action\Trip;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\EntryGuard;
use Logbook\Service\Trip\TripForm;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/trips/{entry}/edit — the driver's own trip, or any
 * trip for Manage (spec.md §7.21, §7.22).
 */
final readonly class EditTripAction
{
    public function __construct(
        private TripService $trips,
        private TripFormPage $page,
        private TripSaved $saved,
        private AttachmentUpload $upload,
        private Redirector $redirect,
        private EntryGuard $guard,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $trip = TripRoute::trip($this->trips, $user, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $trip->createdBy);

        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, $user, $vehicle, TripForm::values($trip, $user->preferences), $trip);
        }

        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $data = TripForm::parse(RequestContext::form($request), $user->preferences, $today);
        $files = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            return $this->page->render($request, $response, $user, $vehicle, RequestContext::formValues($request), $trip, $errors, 422);
        }

        $saveJourney = (RequestContext::form($request)['save_journey'] ?? '') === '1';
        $this->trips->update($vehicle, $trip, $data, $files, $saveJourney);
        $this->saved->flash($request, $vehicle, $data, 'trip.updated');

        return $this->redirect->backOr($request, 'trips.index', ['id' => (string) $vehicle->id]);
    }
}
