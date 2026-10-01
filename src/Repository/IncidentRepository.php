<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\Incident\NcdEffect;
use Logbook\Domain\Incident\Severity;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use UnexpectedValueException;

/**
 * Incidents (`incidents`, spec.md §6 Incident) and the `incident_id` links
 * on maintenance entries, expenses and tyre changes. Vehicle queries are
 * scoped to a vehicle the caller has already resolved.
 */
final readonly class IncidentRepository
{
    private const string TABLE = 'incidents';
    public const int MONEY_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<Incident> open ones first, then newest first: by date, then the latest logged
     */
    public function listForVehicle(int $vehicleId): array
    {
        $rows = $this->select()
            ->where('vehicle_id = :vehicle')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->fetchAllAssociative();
        $incidents = array_values(array_map($this->hydrate(...), $rows));
        usort($incidents, static fn (Incident $a, Incident $b): int => self::openFirst($a, $b) ?: self::newestFirst($a, $b));

        return $incidents;
    }

    /**
     * Incidents of several vehicles from a calendar date (the claims history).
     *
     * @param list<int> $vehicleIds
     * @param DateTimeImmutable|null $from inclusive; null = from the start
     * @param DateTimeImmutable|null $until inclusive; null = to the end
     * @return list<Incident> newest first
     */
    public function listForVehicles(array $vehicleIds, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $query = $this->select()
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER);
        if ($from !== null) {
            $query->andWhere('occurred_on >= :from')->setParameter('from', $from->format('Y-m-d'));
        }
        if ($until !== null) {
            $query->andWhere('occurred_on <= :until')->setParameter('until', $until->format('Y-m-d'));
        }
        $incidents = array_values(array_map($this->hydrate(...), $query->fetchAllAssociative()));
        usort($incidents, self::newestFirst(...));

        return $incidents;
    }

    public function find(int $vehicleId, int $id): ?Incident
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, IncidentData $data, DateTimeImmutable $now, ?int $createdBy): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_by' => $createdBy,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), self::types() + ['vehicle_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, IncidentData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            self::types() + ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Deleting unlinks its records (`ON DELETE SET NULL`) and removes its reading (`CASCADE`).
     */
    public function delete(int $vehicleId, int $id): void
    {
        $this->connection->delete(
            self::TABLE,
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Link a record of the vehicle to an incident, or unlink it (null). A
     * service record takes its linked tyre changes with it (spec.md §7.29,
     * #103).
     */
    public function setLink(LinkKind $kind, int $vehicleId, int $recordId, ?int $incidentId): void
    {
        $types = ['incident' => ParameterType::INTEGER, 'vehicle' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER];
        $this->connection->createQueryBuilder()
            ->update($kind->table())
            ->set('incident_id', ':incident')
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameters(['incident' => $incidentId, 'vehicle' => $vehicleId, 'id' => $recordId], $types)
            ->executeStatement();
        if ($kind === LinkKind::Maintenance) {
            $this->connection->createQueryBuilder()
                ->update(LinkKind::Tyre->table())
                ->set('incident_id', ':incident')
                ->where('vehicle_id = :vehicle', 'maintenance_entry_id = :id')
                ->setParameters(['incident' => $incidentId, 'vehicle' => $vehicleId, 'id' => $recordId], $types)
                ->executeStatement();
        }
    }

    /**
     * The incident a record is linked to, or null.
     */
    public function linkOf(LinkKind $kind, int $vehicleId, int $recordId): ?int
    {
        $value = $this->connection->createQueryBuilder()
            ->select('incident_id')
            ->from($kind->table())
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $recordId, ParameterType::INTEGER)
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The ids of the records linked to each of a vehicle's incidents.
     *
     * @return array<int, array<value-of<LinkKind>, list<int>>> by incident id
     */
    public function linksForVehicle(int $vehicleId): array
    {
        $links = [];
        foreach (LinkKind::cases() as $kind) {
            $rows = $this->connection->createQueryBuilder()
                ->select('id', 'incident_id')
                ->from($kind->table())
                ->where('vehicle_id = :vehicle', 'incident_id IS NOT NULL')
                ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
                ->orderBy('id')
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $links[Row::int($row, 'incident_id')][$kind->value][] = Row::int($row, 'id');
            }
        }

        return $links;
    }

    private static function openFirst(Incident $a, Incident $b): int
    {
        return ($a->data->status === IncidentStatus::Open ? 0 : 1) <=> ($b->data->status === IncidentStatus::Open ? 0 : 1);
    }

    private static function newestFirst(Incident $a, Incident $b): int
    {
        return ($b->data->occurredOn <=> $a->data->occurredOn)
            ?: (($b->data->occurredAtTime ?? '') <=> ($a->data->occurredAtTime ?? ''))
            ?: $b->id <=> $a->id;
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'vehicle_id',
                'created_by',
                'occurred_on',
                'occurred_at_time',
                'location',
                'type',
                'fault',
                'description',
                'damage_areas',
                'severity',
                'driver_user_id',
                'driver_name',
                'other_party_name',
                'other_party_registration',
                'other_party_insurer',
                'police_reference',
                'status',
                'closed_on',
                'write_off_category',
                'notes',
                'claim_status',
                'insurer',
                'insurance_document_id',
                'claim_number',
                'excess',
                'payout',
                'ncd_affected',
                'claim_updated_on',
                'created_at',
                'updated_at',
            )
            ->from(self::TABLE);
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function dataColumns(IncidentData $data): array
    {
        $claim = $data->claim;

        return [
            'occurred_on' => $data->occurredOn->format('Y-m-d'),
            'occurred_at_time' => $data->occurredAtTime,
            'location' => $data->location,
            'type' => $data->type->value,
            'fault' => $data->fault->value,
            'description' => $data->description,
            'damage_areas' => json_encode(
                array_map(static fn (DamageArea $area): string => $area->value, $data->damageAreas),
                JSON_THROW_ON_ERROR,
            ),
            'severity' => $data->severity?->value,
            'driver_user_id' => $data->driverUserId,
            'driver_name' => $data->driverName,
            'other_party_name' => $data->otherPartyName,
            'other_party_registration' => $data->otherPartyRegistration,
            'other_party_insurer' => $data->otherPartyInsurer,
            'police_reference' => $data->policeReference,
            'status' => $data->status->value,
            'closed_on' => $data->closedOn?->format('Y-m-d'),
            'write_off_category' => $data->writeOff->value,
            'notes' => $data->notes,
            'claim_status' => $claim->status->value,
            'insurer' => $claim->insurer,
            'insurance_document_id' => $claim->insuranceDocumentId,
            'claim_number' => $claim->claimNumber,
            'excess' => $claim->excess,
            'payout' => $claim->payout,
            'ncd_affected' => $claim->ncdAffected->value,
            'claim_updated_on' => $claim->updatedOn?->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private static function types(): array
    {
        return [
            'driver_user_id' => ParameterType::INTEGER,
            'insurance_document_id' => ParameterType::INTEGER,
        ];
    }

    /**
     * @return list<DamageArea> in DamageArea order, unknown codes dropped
     */
    private static function areas(?string $json): array
    {
        $codes = $json === null || $json === '' ? [] : json_decode($json, true);
        $codes = is_array($codes) ? $codes : [];

        return array_values(array_filter(
            DamageArea::cases(),
            static fn (DamageArea $area): bool => in_array($area->value, $codes, true),
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Incident
    {
        $platform = $this->connection->getDatabasePlatform();
        $severity = Row::nullableString($row, 'severity');

        return new Incident(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new IncidentData(
                occurredOn: Row::nullableDate($row, 'occurred_on')
                    ?? throw new UnexpectedValueException('Column "occurred_on" is null.'),
                type: IncidentType::from(Row::string($row, 'type')),
                occurredAtTime: Row::nullableString($row, 'occurred_at_time'),
                location: Row::nullableString($row, 'location'),
                fault: Fault::from(Row::string($row, 'fault')),
                description: Row::nullableString($row, 'description'),
                damageAreas: self::areas(Row::nullableString($row, 'damage_areas')),
                severity: $severity === null ? null : Severity::from($severity),
                driverUserId: Row::nullableInt($row, 'driver_user_id'),
                driverName: Row::nullableString($row, 'driver_name'),
                otherPartyName: Row::nullableString($row, 'other_party_name'),
                otherPartyRegistration: Row::nullableString($row, 'other_party_registration'),
                otherPartyInsurer: Row::nullableString($row, 'other_party_insurer'),
                policeReference: Row::nullableString($row, 'police_reference'),
                status: IncidentStatus::from(Row::string($row, 'status')),
                closedOn: Row::nullableDate($row, 'closed_on'),
                writeOff: WriteOffCategory::from(Row::string($row, 'write_off_category')),
                notes: Row::nullableString($row, 'notes'),
                claim: new Claim(
                    status: ClaimStatus::from(Row::string($row, 'claim_status')),
                    insurer: Row::nullableString($row, 'insurer'),
                    insuranceDocumentId: Row::nullableInt($row, 'insurance_document_id'),
                    claimNumber: Row::nullableString($row, 'claim_number'),
                    excess: Row::nullableDecimal($row, 'excess', self::MONEY_SCALE),
                    payout: Row::nullableDecimal($row, 'payout', self::MONEY_SCALE),
                    ncdAffected: NcdEffect::from(Row::string($row, 'ncd_affected')),
                    updatedOn: Row::nullableDate($row, 'claim_updated_on'),
                ),
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            createdBy: Row::nullableInt($row, 'created_by'),
        );
    }
}
