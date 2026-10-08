<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Incident\ClaimsFilter;
use Logbook\Service\Incident\ClaimsHistory;
use Logbook\Service\Incident\ClaimsRow;
use Logbook\Service\Incident\IncidentAccess;
use Logbook\Service\Incident\IncidentChoices;
use Logbook\Service\Incident\IncidentCosts;
use Logbook\Service\Incident\IncidentForm;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\User\UserDirectory;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;

/**
 * The incident endpoints (spec.md §7.20 *Incidents*, §7.29): a vehicle's
 * incidents and the claims history, each as the key's user may see them
 * (IncidentAccess), and logging one through the form's parser.
 */
final readonly class ApiIncidents
{
    public function __construct(
        private IncidentService $incidents,
        private IncidentAccess $access,
        private ClaimsHistory $history,
        private IncidentChoices $choices,
        private VehicleService $vehicles,
        private UserDirectory $directory,
        private ValidationProblem $validation,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, cursor: string|null}
     */
    public function list(User $user, Vehicle $vehicle, ListQuery $query): array
    {
        $page = $query->page(
            $this->incidents->list($vehicle),
            static fn (Incident $incident): array => [$incident->data->occurredOn, $incident->id],
        );
        $costs = $this->incidents->costsFor($user, $vehicle, $page['items']);
        $links = $this->incidents->links($vehicle);

        return [
            'items' => array_map(
                fn (Incident $incident): array
                    => $this->serialize($user, $vehicle, $incident, $costs[$incident->id] ?? null, $links),
                $page['items'],
            ),
            'cursor' => $page['cursor'],
        ];
    }

    /**
     * One incident as the list returns it (spec.md §7.20 *Phase 39*): the
     * detail fields and amounts under the same rules.
     *
     * @return array<string, mixed>
     */
    public function one(User $user, Vehicle $vehicle, Incident $incident): array
    {
        $costs = $this->incidents->costsFor($user, $vehicle, [$incident]);

        return $this->serialize($user, $vehicle, $incident, $costs[$incident->id] ?? null, $this->incidents->links($vehicle));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(User $user, ClaimsFilter $filter): array
    {
        return array_map(
            static fn (ClaimsRow $row): array => Serializer::claimsRow($row),
            $this->history->report($user, $filter)->rows,
        );
    }

    /**
     * Log an incident. A retry with the same date, type and claim number
     * finds the one already logged (spec.md §7.20).
     *
     * @param array<string, mixed> $body
     * @return array{entry: array<string, mixed>, duplicate: bool}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function log(User $user, Vehicle $vehicle, array $body): array
    {
        $result = $this->logIncident($user, $vehicle, $body);

        return [
            'entry' => $this->serialize($user, $vehicle, $result['incident'], null, $this->incidents->links($vehicle)),
            'duplicate' => $result['duplicate'],
        ];
    }

    /**
     * The same write, giving the incident (the draft tools' path, spec.md §7.26).
     *
     * @param array<string, mixed> $body
     * @return array{incident: Incident, duplicate: bool, odometerKm: string|null}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function logIncident(User $user, Vehicle $vehicle, array $body): array
    {
        if ($vehicle->isArchived()) {
            throw new ApiProblem(409, 'vehicle_archived', 'This vehicle is archived; restore it in the app to log entries.');
        }
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $mapped = JsonInput::incident($body, $user->preferences, $today);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $input = IncidentForm::parse(
            $mapped['input'],
            $mapped['preferences'],
            $today,
            $this->choices->driverIds($vehicle),
            $this->choices->policyIds($vehicle),
        );
        if ($input instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($input, JsonInput::INCIDENT_FIELDS));
        }

        foreach ($this->incidents->list($vehicle) as $incident) {
            $data = $input->data;
            if (self::sameKey($incident, $data->occurredOn->format('Y-m-d'), $data->type->value, $data->claim->claimNumber)) {
                return [
                    'incident' => $incident,
                    'duplicate' => true,
                    'odometerKm' => $this->incidents->odometerOf($vehicle, $incident),
                ];
            }
        }

        $incident = $this->incidents->create($vehicle, $input->data, $input->odometerKm, $zone);

        return ['incident' => $incident, 'duplicate' => false, 'odometerKm' => $input->odometerKm];
    }

    private static function sameKey(Incident $incident, string $date, string $type, ?string $claimNumber): bool
    {
        return $incident->data->occurredOn->format('Y-m-d') === $date
            && $incident->data->type->value === $type
            && $incident->data->claim->claimNumber === $claimNumber;
    }

    /**
     * @param array<int, array<value-of<LinkKind>, list<int>>> $links
     * @return array<string, mixed>
     */
    private function serialize(
        User $user,
        Vehicle $vehicle,
        Incident $incident,
        ?IncidentCosts $costs,
        array $links,
    ): array {
        $view = $this->access->view($user, $vehicle, $incident, $costs);
        $data = $incident->data;
        $driver = $data->driverUserId === null ? $data->driverName : $this->directory->displayName($data->driverUserId);

        return Serializer::incident(
            $view,
            $driver,
            $this->incidents->odometerOf($vehicle, $incident),
            $this->vehicles->currencyFor($user, $vehicle),
            $links[$incident->id] ?? [],
            $incident->createdAt,
            $incident->updatedAt,
        );
    }
}
