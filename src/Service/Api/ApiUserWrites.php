<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\PriceAlert;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Trip\SavedJourney;
use Logbook\Domain\User\User;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Attention\AttentionHiding;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\PriceAlertRefused;
use Logbook\Service\FuelPrices\PriceAlerts;
use Logbook\Service\Station\StationService;
use Logbook\Service\Trip\SavedJourneyForm;
use Logbook\Service\Trip\SavedJourneyService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;

/**
 * The key user's own writes (Phase 39.2, spec.md §7.20 *Writes, edits and
 * deletes*), each through the page's service: station favourites (§7.33),
 * saved journeys (Settings → Trips, §7.22), price alerts (§7.34) and
 * *Hide* on a *Needs attention* item (§7.24).
 */
final readonly class ApiUserWrites
{
    public function __construct(
        private StationService $stations,
        private SavedJourneyService $journeys,
        private PriceAlerts $alerts,
        private FuelPriceConfig $prices,
        private AttentionHiding $hiding,
        private ApiReader $reader,
        private VehicleAccess $access,
        private ValidationProblem $validation,
    ) {
    }

    /**
     * `PUT` (true) or `DELETE` (false) `/stations/{id}/favourite`: the
     * station page's star, idempotent. Unstarring removes its price alerts,
     * as on the page.
     *
     * @throws ApiProblem 404 for no such station (or a merged one)
     */
    public function favourite(User $user, int $stationId, bool $favourite): void
    {
        $station = $this->activeStation($stationId);
        if ($this->stations->isFavourite($user, $station) !== $favourite) {
            $this->stations->setFavourite($user, $station, $favourite);
        }
    }

    /**
     * `POST /attention/{key}/hide`: the page's *Hide*, for the key's user,
     * judged again first (spec.md §7.24). `Log` on the vehicle, as the
     * page. Hiding what is hidden already is a no-op.
     *
     * @throws ApiProblem 404 when the key names nothing hideable now, 403, 409
     */
    public function hide(User $user, string $key): void
    {
        if (preg_match('/^([1-9][0-9]{0,18})\.([a-z_]+)\.([0-9]{1,19})\.([A-Za-z0-9_-]+)$/', $key, $m) !== 1) {
            throw ApiProblem::notFound('There is no such item to hide.');
        }
        $vehicle = $this->reader->visibleVehicle($user, (int) $m[1]) ?? throw ApiProblem::notFound('There is no such vehicle.');
        if (!$this->access->can($user, VehicleAbility::Log, $vehicle)) {
            throw new ApiProblem(403, 'forbidden', 'The key\'s user may see this vehicle but may not hide its items.');
        }
        ApiWriter::assertActive($vehicle);
        if (!$this->hiding->hide($user, $vehicle, $m[2], (int) $m[3], $m[4])) {
            throw ApiProblem::notFound('The item changed or is gone; read GET /attention again for its key.');
        }
    }

    /**
     * `POST /journeys` and `PATCH /journeys/{id}` (with `$id`): the journey
     * form, in km, one way. Another user's journey is not found.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> the journey as `GET /journeys` lists it
     * @throws ApiProblem 404, 422
     */
    public function saveJourney(User $user, array $body, ?int $id = null): array
    {
        $journey = $id === null ? null : $this->journey($user, $id);
        $mapped = JsonInput::journey($body, $user->preferences);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $input = $journey === null
            ? $mapped['input']
            : JsonInput::overlay(
                SavedJourneyForm::values($journey, $mapped['preferences']),
                $body,
                $mapped['input'],
                JsonInput::JOURNEY_FIELDS,
            );
        $data = SavedJourneyForm::parse($input, $mapped['preferences']);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::JOURNEY_FIELDS));
        }
        if ($journey === null) {
            $journey = $this->journeys->create($user, $data);
        } else {
            $this->journeys->update($user, $journey, $data);
            $journey = $this->journey($user, $journey->id);
        }

        return Serializer::savedJourney($journey);
    }

    /**
     * `DELETE /journeys/{id}`: trips logged from it keep what they copied.
     *
     * @throws ApiProblem 404
     */
    public function deleteJourney(User $user, int $id): void
    {
        $this->journeys->delete($user, $this->journey($user, $id));
    }

    /**
     * `POST /fuel-prices/alerts`: the station page's alert form (§7.34,
     * #138): a favourite with listed prices, a grade it sells, a price in
     * range, at most PriceAlerts::MAX_PER_USER. An alert for that station
     * and grade already set is changed, as the form changes it.
     *
     * @param array<string, mixed> $body
     * @return array{alert: array<string, mixed>, created: bool}
     * @throws ApiProblem 404 with prices off, 422
     */
    public function setAlert(User $user, array $body): array
    {
        $currency = $this->currency();
        $mapped = JsonInput::priceAlert($body, $user->preferences);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $errors = new ValidationErrors();
        foreach (['station_id', 'grade', 'below'] as $field) {
            if ($mapped[$field] === '') {
                $errors->add($field, 'validation.required');
            }
        }
        $grade = FuelGrade::tryFrom($mapped['grade']);
        if ($mapped['grade'] !== '' && $grade === null) {
            $errors->add('grade', 'validation.choice');
        }
        $station = $mapped['station_id'] === '' ? null : $this->stations->find((int) $mapped['station_id']);
        if ($mapped['station_id'] !== '' && ($station === null || $station->isMerged())) {
            $errors->add('station_id', 'api.validation.station_unknown');
        }
        if (!$errors->isEmpty() || $station === null || $grade === null) {
            throw $this->validation->of($errors);
        }
        $existed = isset($this->alerts->forStation($user, $station)[$grade->value]);
        $this->set($user, $station, $grade, $mapped['below'], $mapped['volume']);

        return ['alert' => Serializer::priceAlert($this->alertFor($user, $station, $grade), $currency), 'created' => !$existed];
    }

    /**
     * `PATCH /fuel-prices/alerts/{id}`: a new price (the station and grade
     * are the alert's own; set another alert for another).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws ApiProblem 404, 422
     */
    public function changeAlert(User $user, int $id, array $body): array
    {
        $currency = $this->currency();
        $alert = $this->alert($user, $id);
        $mapped = JsonInput::priceAlert($body, $user->preferences);
        $errors = $mapped instanceof ValidationErrors ? $mapped : new ValidationErrors();
        foreach (['station_id', 'grade'] as $field) {
            if (array_key_exists($field, $body)) {
                $errors->add($field, 'api.validation.unknown_field');
            }
        }
        if (!is_array($mapped) || !$errors->isEmpty()) {
            throw $this->validation->of($errors);
        }
        $station = $this->stations->find($alert->stationId) ?? throw ApiProblem::notFound('There is no such alert.');
        if (array_key_exists('below', $body)) {
            if ($mapped['below'] === '') {
                $errors->add('below', 'validation.required');
                throw $this->validation->of($errors);
            }
            $this->set($user, $station, $alert->grade, $mapped['below'], $mapped['volume']);
        }

        return Serializer::priceAlert($this->alertFor($user, $station, $alert->grade), $currency);
    }

    /**
     * `DELETE /fuel-prices/alerts/{id}`.
     *
     * @throws ApiProblem 404
     */
    public function removeAlert(User $user, int $id): void
    {
        $this->currency();
        $alert = $this->alert($user, $id);
        $station = $this->stations->find($alert->stationId) ?? throw ApiProblem::notFound('There is no such alert.');
        $this->alerts->remove($user, $station, $alert->grade);
    }

    /**
     * @param string $typed the price per `$volume`, as sent
     * @throws ApiProblem 422 with the form's refusal
     */
    private function set(User $user, Station $station, FuelGrade $grade, string $typed, VolumeUnit $volume): void
    {
        try {
            $this->alerts->set($user, $station, $grade, $volume->pricePerLitre($typed, 3));
        } catch (PriceAlertRefused $refused) {
            $errors = new ValidationErrors();
            $errors->add(match ($refused->reason) {
                'grade' => 'grade',
                'price' => 'below',
                default => 'station_id',
            }, $refused->messageKey());
            throw $this->validation->of($errors);
        }
    }

    /**
     * The provider's currency; 404 while fuel prices are off (§7.34).
     */
    private function currency(): string
    {
        return ($this->prices->provider() ?? throw ApiProblem::notFound('Fuel prices are off on this install.'))->currency();
    }

    private function alert(User $user, int $id): PriceAlert
    {
        foreach ($this->alerts->forUser($user) as $alert) {
            if ($alert->id === $id) {
                return $alert;
            }
        }

        throw ApiProblem::notFound('There is no such alert.');
    }

    private function alertFor(User $user, Station $station, FuelGrade $grade): PriceAlert
    {
        return $this->alerts->forStation($user, $station)[$grade->value]
            ?? throw new \LogicException('The alert was just saved.');
    }

    private function journey(User $user, int $id): SavedJourney
    {
        return $this->journeys->find($user, $id) ?? throw ApiProblem::notFound('There is no such journey.');
    }

    private function activeStation(int $id): Station
    {
        $station = $this->stations->find($id);
        if ($station === null || $station->isMerged()) {
            throw ApiProblem::notFound('There is no such station.');
        }

        return $station;
    }
}
