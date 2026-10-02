<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\JsonDoc;

/**
 * `incidents` and `draft_incident` (spec.md §7.26, §7.29): the claims
 * history's figures, sold vehicles included and never the other party;
 * a draft card whose Add writes the incident with the policy's insurer.
 */
final class IncidentsToolTest extends ToolsBTestCase
{
    public function testHaveIHadAnyClaimsInTheLastFiveYears(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app);
        $fiesta = $this->vehicle($app, 'Ford', 'Fiesta');
        $zone = new DateTimeZone('Europe/London');
        $incidents = $this->service($app, IncidentService::class);
        $day = static fn (string $date) => LocalTime::parseDate($date) ?? throw new \LogicException($date);
        $parked = new IncidentData($day('2026-03-14'), IncidentType::ParkedDamage, otherPartyName: 'A. Driver');
        $incidents->create($golf, $parked, null, $zone);
        $incidents->create($fiesta, new IncidentData(
            $day('2023-05-02'),
            IncidentType::Collision,
            claim: new Claim(ClaimStatus::Settled, 'Aviva', claimNumber: 'AB-77', payout: '900.000', repairEstimate: '1100.000'),
        ), null, $zone);
        $this->service($app, VehicleService::class)->archive($owner, $fiesta);
        $this->assertSchemaAccepts($app, $owner, 'incidents', ['years' => 5, 'claims_only' => true]);

        $all = $this->toolResult($app, $owner, 'incidents');
        $data = new JsonDoc($all->data);
        self::assertSame(2, $data->int('incidents'));
        self::assertSame(1, $data->int('claims'));
        self::assertSame('AB-77', $data->get('rows', 1, 'claim_number'));
        self::assertTrue($data->get('rows', 1, 'sold_or_archived'));
        self::assertSame('£1,100.00', $data->get('rows', 1, 'repair_estimate'));
        self::assertNull($data->get('rows', 0, 'repair_estimate'));
        self::assertStringNotContainsString('A. Driver', json_encode($all->data, JSON_THROW_ON_ERROR));
        self::assertSame('/incidents/history?years=5', $all->link);

        $claims = new JsonDoc($this->toolResult($app, $owner, 'incidents', ['claims_only' => true])->data);
        self::assertSame(1, $claims->int('incidents'));
    }

    public function testADraftIncidentIsACardUntilAddAndTakesThePolicysInsurer(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app);
        $this->document($app, $golf, ComplianceType::Insurance, '2026-01-01', '2026-12-31', '520.00', 'Direct Line');

        $result = $this->toolResult($app, $owner, 'draft_incident', [
            'vehicle' => $golf->id,
            'type' => 'pothole',
            'damage_areas' => ['wheels'],
            'claim_status' => 'notified',
        ]);
        $card = new JsonDoc($result->data);
        self::assertSame('Direct Line', $card->get('fields', 5, 'value'));
        $before = $this->service($app, IncidentRepository::class)->listForVehicle($golf->id);
        self::assertSame([], $before, 'nothing saved yet');

        $this->service($app, DraftStore::class)->apply($owner, $card->int('draft_id'));
        $saved = $this->service($app, IncidentRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $saved);
        self::assertSame(IncidentType::Pothole, $saved[0]->data->type);
        self::assertSame([DamageArea::Wheels], $saved[0]->data->damageAreas);
        self::assertSame('Direct Line', $saved[0]->data->claim->insurer, 'the policy current on the date');
    }

    public function testTheModuleOffHidesBothTools(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_INCIDENTS' => 'false']);
        $this->vehicle($app);

        self::assertFalse($this->isOffered($app, $owner, 'incidents'));
        self::assertFalse($this->isOffered($app, $owner, 'draft_incident'));
    }
}
