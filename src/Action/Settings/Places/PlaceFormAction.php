<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Places;

use Logbook\Domain\Station\Place;
use Logbook\Service\Station\PlaceForm;
use Logbook\Service\Station\PlaceService;
use Logbook\Service\Station\StationListing;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET/POST /settings/places/new and /settings/places/{place}/edit — a place
 * set by typing coordinates, copying a station's position
 * (`?station={id}`), or *Use my current location* (spec.md §7.33).
 */
final readonly class PlaceFormAction
{
    public function __construct(
        private PlaceService $places,
        private StationService $stations,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $place = null;
        if (isset($args['place'])) {
            $place = $this->places->find($user, (int) $args['place']) ?? throw new HttpNotFoundException($request);
        }

        if ($request->getMethod() !== 'POST') {
            $values = $place === null ? $this->defaults($request) : PlaceForm::values($place);

            return $this->render($request, $response, $values, $place, null, 200);
        }

        $data = PlaceForm::parse(RequestContext::form($request), $user->preferences->locale);
        if ($data instanceof ValidationErrors) {
            return $this->render($request, $response, RequestContext::formValues($request), $place, $data, 422);
        }
        if ($place === null) {
            $this->places->create($user, $data);
        } else {
            $this->places->update($user, $place, $data);
        }
        RequestContext::session($request)->flash('success', 'places.saved');

        return $this->redirect->toRoute('settings.places');
    }

    /**
     * A new place: *Home* first, *Work* second, then blank; with
     * `?station=`, that station's position.
     *
     * @return array<string, string>
     */
    private function defaults(ServerRequestInterface $request): array
    {
        $count = count($this->places->list(RequestContext::requireUser($request)));
        $values = ['name' => ''];
        $station = $request->getQueryParams()['station'] ?? '';
        $from = is_string($station) && ctype_digit($station) ? $this->stations->resolve((int) $station) : null;
        if ($from !== null && $from->data->latitude !== null && $from->data->longitude !== null) {
            $values['latitude'] = Decimal::trim($from->data->latitude);
            $values['longitude'] = Decimal::trim($from->data->longitude);
        }
        $values['_suggest'] = $count === 0 ? 'home' : ($count === 1 ? 'work' : '');

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $values,
        ?Place $place,
        ?ValidationErrors $errors,
        int $status,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'settings/places/form.twig', [
            'place' => $place,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'stations' => array_values(array_filter(
                $this->stations->listing(RequestContext::requireUser($request)),
                static fn (StationListing $row): bool => $row->station->data->hasPosition(),
            )),
        ], $status);
    }
}
