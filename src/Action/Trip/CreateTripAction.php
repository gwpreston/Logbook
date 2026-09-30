<?php

declare(strict_types=1);

namespace Logbook\Action\Trip;

use DateTimeImmutable;
use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Service\Trip\SavedJourneyService;
use Logbook\Service\Trip\TripForm;
use Logbook\Service\Trip\TripNotFound;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/trips/new — log a trip (spec.md §7.22).
 * `?journey=<id>` pre-fills a saved journey without JS, and `?again=<id>`
 * is *Log again*: every field of that trip but the date and odometers.
 * Archived vehicles take no new trips.
 */
final readonly class CreateTripAction
{
    public function __construct(
        private TripService $trips,
        private SavedJourneyService $journeys,
        private TripFormPage $page,
        private TripSaved $saved,
        private AttachmentUpload $upload,
        private Redirector $redirect,
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
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        if ($vehicle->isArchived()) {
            RequestContext::session($request)->flash('error', 'trip.error.archived');

            return $this->redirect->toRoute('trips.index', ['id' => (string) $vehicle->id]);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, $user, $vehicle, $this->prefill($request, $today));
        }

        $data = TripForm::parse(RequestContext::form($request), $user->preferences, $today);
        $files = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            return $this->page->render($request, $response, $user, $vehicle, RequestContext::formValues($request), null, $errors, 422);
        }

        $saveJourney = (RequestContext::form($request)['save_journey'] ?? '') === '1';
        $this->trips->create($vehicle, $data, $files, $saveJourney);
        $this->saved->flash($request, $vehicle, $data, 'trip.created');

        return $this->redirect->backOr($request, 'trips.index', ['id' => (string) $vehicle->id]);
    }

    /**
     * @return array<string, string>
     */
    private function prefill(ServerRequestInterface $request, DateTimeImmutable $today): array
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams();

        $again = $query['again'] ?? null;
        if (is_string($again) && ctype_digit($again)) {
            try {
                return TripForm::again($this->trips->get($user, $vehicle, (int) $again), $user->preferences, $today);
            } catch (TripNotFound) {
                // Not theirs to copy: an empty form.
            }
        }

        $journey = $query['journey'] ?? null;
        if (is_string($journey) && ctype_digit($journey)) {
            $saved = $this->journeys->find($user, (int) $journey);
            if ($saved !== null) {
                return TripForm::fromJourney($saved, $user->preferences, $today);
            }
        }

        return TripForm::defaults($today);
    }
}
