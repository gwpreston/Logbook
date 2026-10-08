<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Tyre\TyreChangeForm;
use Logbook\Service\Tyre\TyreChangeNotFound;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreFormContexts;
use Logbook\Service\Tyre\TyreNotFound;
use Logbook\Service\Tyre\TyreService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Api\TyreInput;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DepthUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;

/**
 * Tyre changes and tyre details over the API (Phase 39.2, spec.md §7.17,
 * §7.20), module `tyres`. A change of any kind but a tread check (`POST
 * …/tyres/checks`) is **replayed** through the change form and
 * TyreChangeService::record, as the page, so every state the form refuses
 * is refused (422). An edit changes what the page's edit does (date,
 * odometer, note, service-record link); a delete replays the rest and is
 * refused (409 `tyre_change_refused`) where the page refuses it. A tyre's
 * own details (brand, model, size, season, DOT, notes) are the tyre edit
 * form (`Manage`); its status and position come only from changes.
 */
final readonly class ApiTyreWrites
{
    private const string LOCALE = 'en';
    /** The change edit form's fields under the API's names. */
    private const array EDIT_FIELDS = [
        'changed_on' => 'done_on',
        'odometer' => 'odometer',
        'note' => 'note',
        'service_record_id' => 'link',
    ];
    private const array TYRE_FIELDS = [
        'brand' => 'brand', 'model' => 'model', 'size' => 'size', 'season' => 'season', 'dot' => 'dot', 'notes' => 'notes',
    ];

    public function __construct(
        private TyreChangeService $changes,
        private TyreService $tyres,
        private TyreFormContexts $contexts,
        private OdometerService $odometer,
        private EntryAccess $access,
        private ApiEditor $editor,
        private ApiReader $reader,
        private ValidationProblem $validation,
        private ClockInterface $clock,
    ) {
    }

    /**
     * `POST /vehicles/{id}/tyres/changes` (`Log`).
     *
     * @param array<string, mixed> $body
     * @return array{change: array<string, mixed>, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409, 422
     */
    public function record(User $user, Vehicle $vehicle, array $body): array
    {
        ApiWriter::assertActive($vehicle);
        $code = $body['kind'] ?? null;
        $kind = is_string($code) && in_array($code, TyreInput::KINDS, true) ? TyreChangeKind::from($code) : null;
        if ($kind === null) {
            $errors = new ValidationErrors();
            $errors->add('kind', $code === null ? 'validation.required' : 'validation.choice');
            throw $this->validation->of($errors);
        }
        $preferences = $this->units($user->preferences, $body);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $on = is_string($body['changed_on'] ?? null) ? (LocalTime::parseDate($body['changed_on']) ?? $today) : $today;
        $context = $this->contexts->for($vehicle, $today, $on);
        $mapped = TyreInput::map($kind, $body, $today->format('Y-m-d'), $context);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $parsed = TyreChangeForm::parse($kind, $mapped['input'], $preferences, $context);
        if ($parsed instanceof ValidationErrors) {
            throw $this->validation->of(TyreInput::renamed($parsed, $mapped['paths']));
        }
        try {
            $change = $this->changes->record($vehicle, $parsed, $user->preferences->timeZone(), $user->preferences->locale);
        } catch (TyreChangeRefused $refused) {
            throw $this->validation->of(TyreInput::renamed(ApiWriter::refusal($refused), $mapped['paths']));
        }

        return ['change' => $this->serialize($vehicle, $change), 'warnings' => $this->warnings($vehicle, $change)];
    }

    /**
     * `PATCH …/tyres/changes/{change}`: date, odometer, note and link, as the page's edit.
     *
     * @param array<string, mixed> $body
     * @return array{change: array<string, mixed>, tag: string, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 403, 404, 409, 412, 422
     */
    public function update(User $user, Vehicle $vehicle, int $id, array $body, ?string $ifMatch): array
    {
        $change = $this->guarded($user, $vehicle, $id, $ifMatch);
        $errors = new ValidationErrors();
        foreach (array_keys($body) as $name) {
            if (!array_key_exists($name, self::EDIT_FIELDS) && $name !== 'distance_unit') {
                $errors->add($name, 'api.validation.unknown_field');
            }
        }
        if (!$errors->isEmpty()) {
            throw $this->validation->of($errors);
        }
        $preferences = $this->units($user->preferences, $body, array_key_exists('odometer', $body));
        $sent = [];
        foreach (self::EDIT_FIELDS as $api => $form) {
            if (array_key_exists($api, $body)) {
                $value = $body[$api];
                if ($value !== null && !is_string($value)) {
                    $errors->add($api, 'api.validation.string');
                }
                $sent[$form] = is_string($value) ? trim($value) : '';
            }
        }
        if (!$errors->isEmpty()) {
            throw $this->validation->of($errors);
        }
        $input = $sent + TyreChangeForm::values($change, $preferences);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $on = LocalTime::parseDate($input['done_on']) ?? $change->data->doneOn;
        $context = $this->contexts->for($vehicle, $today, $on, $change->data->maintenanceEntryId);
        $data = TyreChangeForm::parseEdit($change, $input, $preferences, $context);
        $fields = array_flip(self::EDIT_FIELDS);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(TyreInput::renamed($data, $fields));
        }
        try {
            $updated = $this->changes->update($vehicle, $change, $data, $user->preferences->timeZone());
        } catch (TyreChangeRefused $refused) {
            throw $this->validation->of(TyreInput::renamed(ApiWriter::refusal($refused), $fields));
        }

        return [
            'change' => $this->serialize($vehicle, $updated),
            'tag' => $this->editor->tag($this->changes->get($vehicle, $id)),
            'warnings' => $this->warnings($vehicle, $updated),
        ];
    }

    /**
     * `DELETE …/tyres/changes/{change}`: the rest is replayed without it.
     *
     * @throws ApiProblem 403, 404, 409 (`tyre_change_refused` where the page refuses), 412
     */
    public function delete(User $user, Vehicle $vehicle, int $id, ?string $ifMatch): void
    {
        $change = $this->guarded($user, $vehicle, $id, $ifMatch);
        try {
            $this->changes->delete($vehicle, $change);
        } catch (TyreChangeRefused $refused) {
            $problem = $this->validation->of(ApiWriter::refusal($refused));
            $message = array_values($problem->errors)[0]['message'] ?? 'Later changes depend on this one.';
            throw new ApiProblem(409, 'tyre_change_refused', $message, $problem->errors);
        }
    }

    /**
     * `PATCH /vehicles/{id}/tyres/{tyre}` (`Manage`): the tyre edit form.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> the tyre as `GET …/tyres` lists it
     * @throws ApiProblem 404, 409, 422
     */
    public function updateTyre(User $user, Vehicle $vehicle, int $id, array $body): array
    {
        try {
            $tyre = $this->tyres->tyre($vehicle, $id);
        } catch (TyreNotFound) {
            throw ApiProblem::notFound('The vehicle has no such tyre.');
        }
        ApiWriter::assertActive($vehicle);
        $errors = new ValidationErrors();
        $sent = [];
        foreach ($body as $name => $value) {
            if (!array_key_exists($name, self::TYRE_FIELDS)) {
                $errors->add($name, 'api.validation.unknown_field');
            } elseif ($value !== null && !is_string($value)) {
                $errors->add($name, 'api.validation.string');
            } else {
                $sent[$name] = $value ?? '';
            }
        }
        if (!$errors->isEmpty()) {
            throw $this->validation->of($errors);
        }
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $input = $sent + TyreChangeForm::tyreValues($tyre);
        $data = TyreChangeForm::parseTyre($input, $this->units($user->preferences, []), $today);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of($data);
        }
        $this->tyres->updateTyre($vehicle, $tyre, $data);
        foreach ($this->reader->tyres($user, $vehicle) as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        throw new \LogicException('A tyre just edited is missing from its vehicle.');
    }

    /**
     * A change the key's user may change (`canChange`), on an active vehicle, as `If-Match` names it.
     */
    private function guarded(User $user, Vehicle $vehicle, int $id, ?string $ifMatch): TyreChange
    {
        try {
            $change = $this->changes->get($vehicle, $id);
        } catch (TyreChangeNotFound) {
            throw ApiProblem::notFound('The vehicle has no such tyre change.');
        }
        if (!$this->access->canChange($user, $vehicle, $change->createdBy)) {
            throw new ApiProblem(403, 'forbidden', 'The key\'s user may not change this entry: it is someone else\'s.');
        }
        ApiWriter::assertActive($vehicle);
        $this->editor->precondition($ifMatch, $change);

        return $change;
    }

    /**
     * The form's preferences: "." decimals, UTC, and the body's units (the
     * owner's when left out). On an edit that sends no odometer, km, so the
     * stored one round-trips exactly.
     *
     * @param array<string, mixed> $body
     */
    private function units(DisplayPreferences $owner, array $body, bool $ownerDistance = true): DisplayPreferences
    {
        $distance = is_string($body['distance_unit'] ?? null) ? DistanceUnit::tryFrom($body['distance_unit']) : null;
        $depth = is_string($body['depth_unit'] ?? null) ? DepthUnit::tryFrom($body['depth_unit']) : null;
        foreach (['distance_unit' => $distance, 'depth_unit' => $depth] as $name => $unit) {
            if (array_key_exists($name, $body) && $unit === null) {
                $errors = new ValidationErrors();
                $errors->add($name, 'validation.choice');
                throw $this->validation->of($errors);
            }
        }

        return new DisplayPreferences(
            self::LOCALE,
            'UTC',
            $distance ?? ($ownerDistance ? $owner->distanceUnit : DistanceUnit::Kilometre),
            $owner->volumeUnit,
            $owner->consumptionUnit,
            $owner->currency,
            $owner->theme,
            $owner->accent,
            $depth ?? $owner->depthUnit,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Vehicle $vehicle, TyreChange $change): array
    {
        $byId = [];
        foreach ($this->tyres->tyres($vehicle) as $tyre) {
            $byId[$tyre->id] = $tyre;
        }

        return Serializer::tyreChange($change, $byId);
    }

    /**
     * @return list<array{code: string, detail: string}>
     */
    private function warnings(Vehicle $vehicle, TyreChange $change): array
    {
        $warnings = ApiWriter::odometerWarnings($change->data->maintenanceEntryId === null
            ? $this->odometer->warningForEntry($vehicle, OdometerSource::Tyre, $change->id)
            : $this->odometer->warningForEntry($vehicle, OdometerSource::Maintenance, $change->data->maintenanceEntryId));
        foreach ($this->changes->deeperReadings($vehicle, $change) as $deeper) {
            $warnings[] = [
                'code' => 'tread_deeper',
                'detail' => sprintf(
                    'Tyre %d measured deeper than its last check (%s mm); check the depth.',
                    $deeper->tyre->id,
                    Decimal::trim($deeper->previous->treadMm),
                ),
            ];
        }

        return $warnings;
    }
}
