<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use Logbook\Service\Station\StationForm;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\I18n\Region;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /stations/new — add a station (spec.md §7.33). Any user may; a
 * station with the same name (normalised) is opened instead of a second.
 */
final readonly class CreateStationAction
{
    public function __construct(
        private StationService $stations,
        private StationFormPage $page,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        if ($request->getMethod() !== 'POST') {
            $name = $request->getQueryParams()['name'] ?? '';

            return $this->page->render($request, $response, [
                'name' => is_string($name) ? mb_substr($name, 0, 100) : '',
                'country' => Region::of($user->preferences->locale) ?? '',
            ]);
        }

        $data = StationForm::parse(RequestContext::form($request), $user->preferences->locale);
        if ($data instanceof ValidationErrors) {
            return $this->page->render($request, $response, RequestContext::formValues($request), null, $data, 422);
        }

        $existing = $this->stations->existing($data->name);
        $station = $this->stations->create($user, $data);
        RequestContext::session($request)->flash('success', $existing === null ? 'stations.created' : 'stations.already_exists');

        return $this->redirect->toRoute('stations.show', ['station' => (string) $station->id]);
    }
}
