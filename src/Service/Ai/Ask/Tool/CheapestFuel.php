<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\StationName;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolError;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\FuelPrices\CheapestNear;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\NearForm;
use Logbook\Service\FuelPrices\NearOrigin;
use Logbook\Service\Station\StationService;
use Logbook\Support\Api\FuelPriceSerializer;

/**
 * `cheapest_fuel(vehicle?, grade?, near, radius?, lat?, lng?)` (spec.md
 * §7.26, §7.34): *Cheapest near me* for "Where's the cheapest E10 near
 * work?". `near` is one of the user's places by name, a station by name or
 * id, or `here` with the client's own lat and lng; omitted, the user's
 * first place. A position is used for this answer only and never stored.
 * Offered only while a price provider is enabled.
 */
final readonly class CheapestFuel implements AskTool
{
    private const int LIMIT = 10;

    public function __construct(
        private ToolKit $kit,
        private FuelPriceConfig $config,
        private CheapestNear $near,
        private NearForm $form,
        private StationService $stations,
        private FuelPriceSerializer $serializer,
    ) {
    }

    public function name(): string
    {
        return 'cheapest_fuel';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'The cheapest listed fuel near one of the user\'s places, a station, or a position, ranked by effective cost: '
            . 'the vehicle\'s usual fill plus the fuel to drive there and back '
            . '(straight-line distance × 1.3 at its usual economy). '
            . 'Each row has the listed price and when it was reported, the effective cost, '
            . 'and the sum against the nearest station. '
            . 'Quote the attribution with the answer.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicle' => ['type' => 'integer', 'description' => 'A vehicle id; default the one filled most recently.'],
                    'grade' => [
                        'type' => 'string',
                        'description' => 'A grade code such as e10_95, e5_97 or b7; default the vehicle\'s usual grade.',
                    ],
                    'near' => [
                        'type' => 'string',
                        'description' => 'A place name ("Home", "Work"), a station name or id, or "here" with lat and lng. '
                            . 'Default: the user\'s first place.',
                    ],
                    'radius' => ['type' => 'number', 'description' => 'In the user\'s distance unit: 2, 5 (default), 10 or 20.'],
                    'lat' => ['type' => 'number', 'description' => 'With near "here": latitude in degrees.'],
                    'lng' => ['type' => 'number', 'description' => 'With near "here": longitude in degrees.'],
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->config->enabled();
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $provider = $this->config->provider() ?? throw new ToolError('Fuel prices are off on this install.');
        $vehicle = $this->vehicle($user, $arguments);
        $origin = $this->origin($user, $arguments);
        $grade = null;
        $code = $arguments->string('grade');
        if ($code !== null && $code !== '') {
            $grade = FuelGrade::tryFrom($code);
            if ($grade === null || !in_array($grade, $this->form->grades($provider, $vehicle, $this->config->settings()), true)) {
                throw new ToolError(sprintf('"%s" is not a grade this provider lists for the vehicle.', $code));
            }
        }
        $radius = is_numeric($arguments->values['radius'] ?? null)
            ? (float) $arguments->values['radius']
            : (float) CheapestNear::DEFAULT_RADIUS;
        $radius = max(0.5, min(20.0, $radius));

        $result = $this->near->search(
            $origin,
            $vehicle,
            $grade,
            $user->preferences->distanceUnit->toKm($radius),
            limit: self::LIMIT,
        ) ?? throw new ToolError('Fuel prices are off on this install.');
        $data = $this->serializer->near($result);
        if ($origin->kind === NearOrigin::HERE) {
            // The client's own position is never echoed back or kept in the thread.
            $data['origin'] = ['kind' => NearOrigin::HERE, 'label' => null];
        }

        $figures = [];
        foreach (array_slice($result->rows, 0, 3) as $row) {
            $figures[] = $this->kit->format->unitPrice($row->listed->price, $provider->currency(), false, true);
        }
        $attribution = $this->serializer->provider($provider)['attribution'];

        return new ToolResult(
            $data,
            $this->kit->source([$this->kit->t('fuel_prices.near.title'), is_string($attribution) ? $attribution : null]),
            $figures,
            $origin->kind === NearOrigin::PLACE
                ? $this->kit->link('/stations/near', ['from' => 'place:' . $origin->id, 'vehicle' => (string) $vehicle->id])
                : '/stations/near',
            [$vehicle->id],
        );
    }

    private function vehicle(User $user, ToolArguments $arguments): Vehicle
    {
        $vehicles = $this->form->vehicles($user);
        if ($arguments->has('vehicle')) {
            $vehicle = $this->kit->vehicle($user, $arguments);
            foreach ($vehicles as $candidate) {
                if ($candidate->id === $vehicle->id) {
                    return $candidate;
                }
            }
            throw new ToolError('That vehicle has no petrol or diesel to compare prices for.');
        }

        return $vehicles[0] ?? throw new ToolError('There is no petrol or diesel vehicle to compare prices for.');
    }

    private function origin(User $user, ToolArguments $arguments): NearOrigin
    {
        $near = trim($arguments->string('near') ?? '');
        $places = $this->form->places($user);
        if ($near === '') {
            $first = $places[0] ?? throw new ToolError('The user has no places; ask which place or station to search near.');

            return NearOrigin::place(
                $first->id,
                $first->data->name,
                (float) $first->data->latitude,
                (float) $first->data->longitude,
            );
        }
        if (strtolower($near) === 'here') {
            return NearOrigin::validPosition($arguments->values['lat'] ?? null, $arguments->values['lng'] ?? null)
                ?? throw new ToolError('No position was given for "here"; ask the user which place or station to search near.');
        }
        foreach ($places as $place) {
            if (StationName::normalise($place->data->name) === StationName::normalise($near)) {
                return NearOrigin::place(
                    $place->id,
                    $place->data->name,
                    (float) $place->data->latitude,
                    (float) $place->data->longitude,
                );
            }
        }
        $station = ctype_digit($near) ? $this->stations->resolve((int) $near) : $this->stations->existing($near);
        if ($station !== null && $station->data->hasPosition()) {
            return NearOrigin::station(
                $station->id,
                $station->data->name,
                (float) $station->data->latitude,
                (float) $station->data->longitude,
            );
        }

        throw new ToolError(sprintf('No place or station with a position is called "%s".', mb_substr($near, 0, 50)));
    }
}
