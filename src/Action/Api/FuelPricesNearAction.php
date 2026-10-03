<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\StationName;
use Logbook\Domain\User\User;
use Logbook\Service\FuelPrices\CheapestNear;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\NearForm;
use Logbook\Service\FuelPrices\NearOrigin;
use Logbook\Service\FuelPrices\NearSort;
use Logbook\Service\Station\StationService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\FuelPriceSerializer;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/fuel-prices/near?vehicle=&grade=&lat=&lng=&radius= (or
 * `place=<name>` or `station=<id>`, exactly one origin) — *Cheapest near me*
 * for the key user (spec.md §7.20, §7.34): the rows ranked by effective
 * cost, with each one's sum against the nearest. A position in the request
 * is used for this answer only and never saved (it is in the request's URL). 404 while no
 * provider is enabled.
 */
final readonly class FuelPricesNearAction
{
    public const float MAX_RADIUS = 50.0;

    public function __construct(
        private FuelPriceConfig $config,
        private CheapestNear $near,
        private NearForm $form,
        private StationService $stations,
        private FuelPriceSerializer $serializer,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $provider = $this->config->provider() ?? throw ApiProblem::notFound('Fuel prices are off on this install.');
        $user = RequestContext::requireUser($request);
        $params = $request->getQueryParams();
        $text = static fn (string $key): string => is_string($params[$key] ?? null) ? trim($params[$key]) : '';

        $vehicles = $this->form->vehicles($user);
        $vehicle = $vehicles[0] ?? throw ApiProblem::notFound('There is no petrol or diesel vehicle to compare prices for.');
        if ($text('vehicle') !== '') {
            $vehicle = null;
            foreach ($vehicles as $candidate) {
                if ((string) $candidate->id === $text('vehicle')) {
                    $vehicle = $candidate;
                }
            }
            $vehicle ?? throw ApiProblem::notFound('There is no such vehicle.');
        }

        $origins = array_filter([
            'position' => $text('lat') !== '' || $text('lng') !== '',
            'place' => $text('place') !== '',
            'station' => $text('station') !== '',
        ]);
        if (count($origins) !== 1) {
            throw ApiProblem::invalidParameter('lat', 'give exactly one of lat and lng, place or station.');
        }
        $origin = match (array_key_first($origins)) {
            'position' => NearOrigin::validPosition($text('lat'), $text('lng'))
                ?? throw ApiProblem::invalidParameter('lat', 'lat and lng must be a position in degrees.'),
            'place' => $this->place($user, $text('place')),
            default => $this->station($text('station')),
        };

        $grade = null;
        if ($text('grade') !== '') {
            $grade = FuelGrade::tryFrom($text('grade'));
            if ($grade === null || !in_array($grade, $this->form->grades($provider, $vehicle, $this->config->settings()), true)) {
                throw ApiProblem::invalidParameter('grade', 'not a grade this provider lists for the vehicle.');
            }
        }
        $typed = $text('radius');
        $radius = $typed === '' ? (float) CheapestNear::DEFAULT_RADIUS : (is_numeric($typed) ? (float) $typed : 0.0);
        if ($radius <= 0 || $radius > self::MAX_RADIUS) {
            throw ApiProblem::invalidParameter('radius', 'a distance above 0 and up to 50, in your distance unit.');
        }
        $sort = $text('sort') === '' ? NearSort::Effective : NearSort::tryFrom($text('sort'));
        if ($sort === null) {
            throw ApiProblem::invalidParameter('sort', 'one of effective, price or distance.');
        }

        $result = $this->near->search(
            $origin,
            $vehicle,
            $grade,
            $user->preferences->distanceUnit->toKm($radius),
            in_array($text('include_older'), ['true', '1'], true),
            $sort,
        ) ?? throw ApiProblem::notFound('Fuel prices are off on this install.');

        return $this->responder->json($this->serializer->near($result));
    }

    private function place(User $user, string $name): NearOrigin
    {
        foreach ($this->form->places($user) as $place) {
            if (StationName::normalise($place->data->name) === StationName::normalise($name)) {
                return NearOrigin::place(
                    $place->id,
                    $place->data->name,
                    (float) $place->data->latitude,
                    (float) $place->data->longitude,
                );
            }
        }

        throw ApiProblem::invalidParameter('place', 'you have no place of that name.');
    }

    private function station(string $id): NearOrigin
    {
        $station = ctype_digit($id) ? $this->stations->resolve((int) $id) : null;
        if ($station === null || !$station->data->hasPosition()) {
            throw ApiProblem::invalidParameter('station', 'no such station with a position.');
        }

        return NearOrigin::station(
            $station->id,
            $station->data->name,
            (float) $station->data->latitude,
            (float) $station->data->longitude,
        );
    }
}
