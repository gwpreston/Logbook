<?php

declare(strict_types=1);

namespace Logbook\Action\Station\Prices;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\FuelPrices\CheapestNear;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\NearForm;
use Logbook\Service\FuelPrices\NearOrigin;
use Logbook\Service\FuelPrices\NearSort;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /stations/near — *Cheapest near me* (spec.md §7.34), a GET form that
 * works without JS. From the browser's current position (`from=here` with
 * `lat`/`lng`, filled by js/stations.js; used for this search only and
 * rounded to about 100 m, never saved by Logbook), a place (`from=place:{id}`) or a station
 * (`from=station:{id}`); for a vehicle and grade, within a radius in the
 * user's distance unit; ranked by effective cost.
 */
final readonly class CheapestNearAction
{
    public function __construct(
        private FuelPriceConfig $config,
        private CheapestNear $near,
        private NearForm $form,
        private View $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $provider = $this->config->provider();
        if ($provider === null) {
            throw new HttpNotFoundException($request);
        }
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams();
        $text = static fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';

        $vehicles = $this->form->vehicles($user);
        $places = $this->form->places($user);
        $stations = $this->form->stations($user);
        $vehicle = NearForm::pick($vehicles, $text('vehicle')) ?? ($vehicles[0] ?? null);

        $from = $text('from');
        if ($from === '') {
            $from = $places === [] ? NearOrigin::HERE : 'place:' . $places[0]->id;
        }
        $origin = NearForm::origin($from, $text('lat'), $text('lng'), $places, $stations);

        $unit = $user->preferences->distanceUnit;
        $radius = (int) $text('radius');
        if (!in_array($radius, CheapestNear::RADII, true)) {
            $radius = CheapestNear::DEFAULT_RADIUS;
        }
        $sort = NearSort::tryFrom($text('sort')) ?? NearSort::Effective;
        $older = $text('older') === '1';
        $grades = $vehicle === null ? [] : $this->form->grades($provider, $vehicle, $this->config->settings());
        $grade = FuelGrade::tryFrom($text('grade'));
        if ($grade !== null && !in_array($grade, $grades, true)) {
            $grade = null;
        }

        $result = $vehicle === null || $origin === null
            ? null
            : $this->near->search($origin, $vehicle, $grade, $unit->toKm((float) $radius), $older, $sort);

        return $this->view->render($request, $response, 'stations/near.twig', [
            'provider' => $provider,
            'vehicles' => $vehicles,
            'vehicle' => $vehicle,
            'places' => $places,
            'stations' => $stations,
            'from' => $from,
            'origin' => $origin,
            // Only echoed back into the form for "here"; never stored.
            'position' => $origin?->kind === NearOrigin::HERE ? ['lat' => $text('lat'), 'lng' => $text('lng')] : null,
            'radii' => CheapestNear::RADII,
            'radius' => $radius,
            'grades' => $grades,
            'grade' => $result->grade ?? $grade,
            'sort' => $sort,
            'older' => $older,
            'result' => $result,
            'currency' => $provider->currency(),
            'searched' => $result !== null,
            'needs_position' => $from === NearOrigin::HERE && $origin === null,
        ]);
    }
}
