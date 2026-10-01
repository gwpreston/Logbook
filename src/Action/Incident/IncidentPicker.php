<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Service\Access\AccessContext;
use Logbook\Service\Incident\IncidentAccess;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Incident\IncidentService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * *Part of an incident* on the maintenance, expense and tyre change forms
 * (spec.md §7.29): the select's options (the vehicle's incidents, open
 * ones first), its preselection from ?incident=, and the link it saves.
 * Nothing at all while the `incidents` module is off: the field is not
 * shown, and a record's existing link is left untouched.
 */
final readonly class IncidentPicker
{
    public const string FIELD = 'incident_id';

    public function __construct(
        private IncidentService $incidents,
        private FeatureToggles $features,
        private IncidentAccess $access,
        private AccessContext $context,
    ) {
    }

    /**
     * The vehicle's incidents the signed-in user may change: the only ones
     * a record can be linked to, as on the incident page.
     *
     * @return list<Incident>
     */
    private function linkable(Vehicle $vehicle): array
    {
        $user = $this->context->user();

        return $user === null ? [] : array_values(array_filter(
            $this->incidents->list($vehicle),
            fn (Incident $incident): bool => $this->access->canChange($user, $vehicle, $incident),
        ));
    }

    /**
     * Template variables: `incident_options` (empty when the module is off).
     *
     * @return array{incident_options: list<array{id: int, date: \DateTimeImmutable, type: string, open: bool}>}
     */
    public function context(Vehicle $vehicle): array
    {
        if (!$this->features->isEnabled(Feature::Incidents)) {
            return ['incident_options' => []];
        }

        return ['incident_options' => array_map(static fn (Incident $incident): array => [
            'id' => $incident->id,
            'date' => $incident->data->occurredOn,
            'type' => $incident->data->type->labelKey(),
            'open' => $incident->data->status === IncidentStatus::Open,
        ], $this->linkable($vehicle))];
    }

    /**
     * A create form's value: ?incident=<id> when it is one of the vehicle's.
     *
     * @param array<string, string> $values
     * @return array<string, string>
     */
    public function prefill(ServerRequestInterface $request, Vehicle $vehicle, array $values): array
    {
        $id = $request->getQueryParams()['incident'] ?? null;
        if (is_string($id) && $this->find($vehicle, $id) !== null) {
            $values[self::FIELD] = $id;
        }

        return $values;
    }

    /**
     * An edit form's value: the record's current incident.
     *
     * @param array<string, string> $values
     * @return array<string, string>
     */
    public function current(?int $incidentId, array $values): array
    {
        return [self::FIELD => $incidentId === null ? '' : (string) $incidentId] + $values;
    }

    /**
     * Save the chosen incident on the record, when the form had the field.
     * An id that is not one of this vehicle's incidents links nothing.
     *
     * @param array<array-key, mixed> $form
     */
    public function save(Vehicle $vehicle, LinkKind $kind, int $recordId, array $form): void
    {
        if (!$this->features->isEnabled(Feature::Incidents) || !array_key_exists(self::FIELD, $form)) {
            return;
        }
        // A link to an incident this user may not change is left as it is.
        $current = $this->incidents->linkOf($vehicle, $kind, $recordId);
        $linkable = array_map(static fn (Incident $incident): int => $incident->id, $this->linkable($vehicle));
        if ($current !== null && !in_array($current, $linkable, true)) {
            return;
        }
        $value = $form[self::FIELD];
        $incident = is_string($value) ? $this->find($vehicle, $value) : null;
        $this->incidents->link($vehicle, $kind, $recordId, $incident);
    }

    /**
     * A tyre change's incident (#103): on its service record when it has
     * one, which the change then follows; else on the change itself. A
     * tread check or the tyres already fitted take none.
     *
     * @param array<array-key, mixed> $form
     */
    public function saveTyre(Vehicle $vehicle, TyreChange $change, array $form): void
    {
        if (!self::takesIncident($change->kind)) {
            return;
        }
        $record = $change->data->maintenanceEntryId;
        if ($record === null) {
            $this->save($vehicle, LinkKind::Tyre, $change->id, $form);
        } elseif (array_key_exists(self::FIELD, $form)) {
            $this->save($vehicle, LinkKind::Maintenance, $record, $form);
        } else {
            $this->incidents->followRecord($vehicle, $change);
        }
    }

    public static function takesIncident(TyreChangeKind $kind): bool
    {
        return $kind !== TyreChangeKind::Check && $kind !== TyreChangeKind::Existing;
    }

    private function find(Vehicle $vehicle, string $id): ?Incident
    {
        if (!ctype_digit($id)) {
            return null;
        }
        foreach ($this->linkable($vehicle) as $incident) {
            if ($incident->id === (int) $id) {
                return $incident;
            }
        }

        return null;
    }
}
