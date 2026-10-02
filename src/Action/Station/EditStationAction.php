<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use Logbook\Service\Station\StationForm;
use Logbook\Service\Station\StationNameTaken;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;

/**
 * GET/POST /stations/{station}/edit — the station's creator or an admin
 * (spec.md §7.33, #132). A name another station has is refused, with a
 * link to merge the two.
 */
final readonly class EditStationAction
{
    public function __construct(
        private StationService $stations,
        private StationFormPage $page,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $station = StationRoute::active($this->stations, $request, $args);
        if (!$this->stations->canEdit($user, $station)) {
            throw new HttpForbiddenException($request);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, StationForm::values($station), $station);
        }

        $data = StationForm::parse(RequestContext::form($request), $user->preferences->locale);
        if ($data instanceof ValidationErrors) {
            return $this->page->render($request, $response, RequestContext::formValues($request), $station, $data, 422);
        }
        try {
            $this->stations->update($station, $data);
        } catch (StationNameTaken $taken) {
            $errors = new ValidationErrors();
            $errors->add('name', 'stations.validation.name_taken');

            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $values, $station, $errors, 422, $taken->other);
        }
        RequestContext::session($request)->flash('success', 'stations.saved');

        return $this->redirect->toRoute('stations.show', ['station' => (string) $station->id]);
    }
}
