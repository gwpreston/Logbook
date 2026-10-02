<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Incident;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Disposal;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Repository\IncidentRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Report\OwnershipService;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Total loss (spec.md §7.29 *Total loss*, Phase 27.2): *Archive* offers
 * *Written off* only for a settled incident with a write-off category, the
 * settlement becomes the sale and counts once in ownership, and the label
 * follows the vehicle whatever modules are on.
 */
final class TotalLossTest extends AppTestCase
{
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private User $owner;
    private Vehicle $golf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->browser = $this->signedIn($this->app);
        $this->owner = $this->owner($this->app);
        $golf = $this->vehicle($this->app);
        $this->golf = $this->vehicles()->update($this->owner, $golf, new VehicleData(
            $golf->data->type,
            $golf->data->make,
            $golf->data->model,
            $golf->data->fuelType,
            registration: $golf->data->registration,
            purchaseDate: self::day('2020-01-01'),
            purchasePrice: '15000.000',
        ));
    }

    public function testWithoutASettledWriteOffArchiveIsOneClick(): void
    {
        // An open claim on a Cat S, and a settled claim that is not a write-off.
        $this->log(new Claim(ClaimStatus::Open, 'Aviva', payout: '9000.000'), WriteOffCategory::CatS);
        $this->log(new Claim(ClaimStatus::Settled, 'Aviva', payout: '400.000'), WriteOffCategory::None);
        $id = $this->golf->id;

        $page = self::body($this->browser->get('/vehicles/' . $id));
        self::assertStringContainsString('<form method="post" action="/vehicles/' . $id . '/archive">', $page);
        self::assertSame(303, $this->browser->get('/vehicles/' . $id . '/archive')->getStatusCode());

        $this->browser->post('/vehicles/' . $id . '/archive');
        $archived = $this->reload();
        self::assertTrue($archived->isArchived());
        self::assertNull($archived->disposal);
        self::assertNull($archived->data->saleDate);
    }

    public function testASettledWriteOffOpensTheConfirmPageWithTheSettlementPrefilled(): void
    {
        $incident = $this->settledCatS();
        $id = $this->golf->id;

        $page = self::body($this->browser->get('/vehicles/' . $id));
        self::assertStringContainsString('href="/vehicles/' . $id . '/archive" data-modal', $page);

        $confirm = self::body($this->browser->get('/vehicles/' . $id . '/archive'));
        self::assertStringContainsString('Written off', $confirm);
        self::assertStringContainsString('Just archive', $confirm);
        self::assertStringContainsString('name="incident_id" value="' . $incident->id . '"', $confirm);
        self::assertStringContainsString('value="2025-03-20"', $confirm, 'the incident was closed on 20 Mar 2025');
        self::assertStringContainsString('value="9000"', $confirm, 'the payout');
    }

    public function testTheSaleDateFallsBackToTheLatestClaimUpdateThenToday(): void
    {
        $this->log(
            new Claim(ClaimStatus::Settled, 'Aviva', payout: '9000.000', updatedOn: self::day('2025-03-18')),
            WriteOffCategory::CatS,
        );
        $confirm = self::body($this->browser->get('/vehicles/' . $this->golf->id . '/archive'));
        self::assertStringContainsString('value="2025-03-18"', $confirm);
    }

    public function testArchivingAsWrittenOffSetsTheDisposalAndTheSaleTogether(): void
    {
        $incident = $this->settledCatS();
        $id = $this->golf->id;

        $response = $this->browser->post('/vehicles/' . $id . '/archive', [
            'disposal' => 'written_off',
            'incident_id' => (string) $incident->id,
            'sale_date' => '2025-03-21',
            'sale_price' => '8950',
        ]);
        self::assertSame(303, $response->getStatusCode());

        $vehicle = $this->reload();
        self::assertTrue($vehicle->isArchived());
        self::assertSame(Disposal::WrittenOff, $vehicle->disposal);
        self::assertSame($incident->id, $vehicle->disposalIncidentId);
        self::assertSame('2025-03-21', $vehicle->data->saleDate?->format('Y-m-d'), 'edited, not the prefilled date');
        self::assertSame('8950.000', $vehicle->data->salePrice);

        $show = self::body($this->browser->follow($response));
        self::assertStringContainsString('was archived as written off', $show);
        self::assertStringContainsString('Written off 21 Mar 2025', $show);
        $garage = self::body($this->browser->get('/garage?archived=1'));
        self::assertStringContainsString('Written off 21 Mar 2025', $garage);
        $history = self::body($this->browser->get('/vehicles/' . $id . '/history?year=2025'));
        self::assertStringContainsString('Total loss: Collision, 14 Mar 2025', $history);
    }

    public function testJustArchiveAndABadChoiceAreHandled(): void
    {
        $incident = $this->settledCatS();
        $id = $this->golf->id;

        $refused = $this->browser->post('/vehicles/' . $id . '/archive', [
            'disposal' => 'written_off',
            'incident_id' => (string) ($incident->id + 99),
            'sale_date' => '2019-06-01',
            'sale_price' => '',
        ]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('before the purchase date', self::body($refused));
        self::assertFalse($this->reload()->isArchived(), 'nothing saved');

        $this->browser->post('/vehicles/' . $id . '/archive', ['disposal' => '']);
        $vehicle = $this->reload();
        self::assertTrue($vehicle->isArchived());
        self::assertNull($vehicle->disposal, '*Just archive* records no reason');
    }

    public function testTheSettlementCountsOnceInOwnership(): void
    {
        $this->log(new Claim(ClaimStatus::Settled, 'Aviva', payout: '400.000'), WriteOffCategory::None, '2023-05-01');
        $incident = $this->settledCatS();
        $this->expense($this->app, $this->golf, '2022-01-01', '3000.00');
        $this->vehicles()->archiveWrittenOff($this->owner, $this->golf, $incident->id, self::day('2025-03-20'), '9000.000');

        $report = $this->service($this->app, OwnershipService::class)->report(
            $this->owner,
            ReportFilter::fromQuery(['include_archived' => '1'], self::day('2026-09-29')),
            self::day('2026-09-29'),
        );
        $cost = $report->rows()[0];
        self::assertSame('400.000', $cost->payouts?->toDecimal(3), 'the repair claim only');
        self::assertTrue($cost->settlementIsSale);
        // £15,000 + £3,000 out; £400 + £9,000 back.
        self::assertSame('8600.000', $cost->total?->toDecimal(3));

        $show = self::body($this->browser->get('/vehicles/' . $this->golf->id));
        self::assertStringContainsString('Settlement counted as the sale price', $show);
        $csv = self::body($this->browser->get('/reports/ownership.csv?include_archived=1'));
        self::assertStringContainsString('written off', $csv);
    }

    public function testRestoreClearsTheDisposalAndKeepsTheSale(): void
    {
        $incident = $this->settledCatS();
        $this->vehicles()->archiveWrittenOff($this->owner, $this->golf, $incident->id, self::day('2025-03-20'), '9000.000');

        $this->browser->post('/vehicles/' . $this->golf->id . '/restore');

        $vehicle = $this->reload();
        self::assertSame(VehicleStatus::Active, $vehicle->status);
        self::assertNull($vehicle->disposal);
        self::assertNull($vehicle->disposalIncidentId);
        self::assertSame('2025-03-20', $vehicle->data->saleDate?->format('Y-m-d'));
        self::assertSame('9000.000', $vehicle->data->salePrice);
    }

    public function testASaleDateMakesItSoldAndClearingItClearsSoldButNeverAWriteOff(): void
    {
        $sold = $this->vehicles()->update($this->owner, $this->golf, $this->withSale($this->golf, '2026-01-10'));
        self::assertSame(Disposal::Sold, $sold->disposal);
        $cleared = $this->vehicles()->update($this->owner, $sold, $this->withSale($sold, null));
        self::assertNull($cleared->disposal, 'decided 2026-10-02 (#105)');

        $incident = $this->settledCatS();
        $this->vehicles()->archiveWrittenOff($this->owner, $cleared, $incident->id, self::day('2025-03-20'), '9000.000');
        $writtenOff = $this->reload();
        $edited = $this->vehicles()->update($this->owner, $writtenOff, $this->withSale($writtenOff, '2025-03-22'));
        self::assertSame(Disposal::WrittenOff, $edited->disposal);
        $edited = $this->vehicles()->update($this->owner, $edited, $this->withSale($edited, null));
        self::assertSame(Disposal::WrittenOff, $edited->disposal, 'the edit form never changes a write-off');
        self::assertSame($incident->id, $edited->disposalIncidentId);
    }

    public function testWithTheModuleOffArchivingIsOneClickAndAWriteOffKeepsItsLabel(): void
    {
        $incident = $this->settledCatS();
        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Incidents)));
        $id = $this->golf->id;

        $page = self::body($this->browser->get('/vehicles/' . $id));
        self::assertStringContainsString('<form method="post" action="/vehicles/' . $id . '/archive">', $page);
        self::assertSame(303, $this->browser->get('/vehicles/' . $id . '/archive')->getStatusCode());
        $this->browser->post('/vehicles/' . $id . '/archive', [
            'disposal' => 'written_off',
            'incident_id' => (string) $incident->id,
            'sale_date' => '2025-03-20',
            'sale_price' => '9000',
        ]);
        self::assertNull($this->reload()->disposal, 'not offered, so not written off');

        // Written off while the module was on: the label stays when it is off.
        $this->vehicles()->restore($this->owner, $this->reload());
        $toggles->save(Feature::cases());
        $this->vehicles()->archiveWrittenOff($this->owner, $this->reload(), $incident->id, self::day('2025-03-20'), '9000.000');
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Incidents)));
        self::assertStringContainsString('Written off 20 Mar 2025', self::body($this->browser->get('/vehicles/' . $id)));
        self::assertStringContainsString('Written off 20 Mar 2025', self::body($this->browser->get('/garage?archived=1')));
    }

    public function testAWrittenOffVehicleCanBeDeletedAndItsIncidentToo(): void
    {
        $incident = $this->settledCatS();
        $this->vehicles()->archiveWrittenOff($this->owner, $this->golf, $incident->id, self::day('2025-03-20'), '9000.000');

        // The vehicle's incidents cascade, and the incident's SET NULL points back at the vehicle being deleted.
        $this->vehicles()->delete($this->owner, $this->reload());

        self::assertNull($this->service($this->app, VehicleRepository::class)->findById($this->golf->id));
        self::assertSame([], $this->service($this->app, IncidentRepository::class)->listForVehicle($this->golf->id));
    }

    public function testDeletingTheTotalLossIncidentLeavesTheVehicleWrittenOff(): void
    {
        $incident = $this->settledCatS();
        $this->vehicles()->archiveWrittenOff($this->owner, $this->golf, $incident->id, self::day('2025-03-20'), '9000.000');

        $this->service($this->app, IncidentService::class)->delete($this->golf, $incident);

        $vehicle = $this->reload();
        self::assertSame(Disposal::WrittenOff, $vehicle->disposal);
        self::assertNull($vehicle->disposalIncidentId);
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }

    private function vehicles(): VehicleService
    {
        return $this->service($this->app, VehicleService::class);
    }

    private function reload(): Vehicle
    {
        $vehicle = $this->service($this->app, VehicleRepository::class)->findById($this->golf->id);
        self::assertNotNull($vehicle);

        return $vehicle;
    }

    private function settledCatS(): Incident
    {
        return $this->log(
            new Claim(
                ClaimStatus::Settled,
                'Aviva',
                claimNumber: 'CLM-4417',
                payout: '9000.000',
                updatedOn: self::day('2025-03-18'),
            ),
            WriteOffCategory::CatS,
            '2025-03-14',
            closedOn: '2025-03-20',
        );
    }

    private function log(
        Claim $claim,
        WriteOffCategory $writeOff,
        string $date = '2025-03-14',
        ?string $closedOn = null,
    ): Incident {
        return $this->service($this->app, IncidentService::class)->create($this->golf, new IncidentData(
            occurredOn: self::day($date),
            type: IncidentType::Collision,
            fault: Fault::AtFault,
            damageAreas: [DamageArea::Front],
            status: $closedOn === null ? IncidentStatus::Open : IncidentStatus::Closed,
            closedOn: $closedOn === null ? null : self::day($closedOn),
            writeOff: $writeOff,
            claim: $claim,
        ), null, new DateTimeZone('Europe/London'));
    }

    private function withSale(Vehicle $vehicle, ?string $on): VehicleData
    {
        $data = $vehicle->data;

        return new VehicleData(
            $data->type,
            $data->make,
            $data->model,
            $data->fuelType,
            registration: $data->registration,
            purchaseDate: $data->purchaseDate,
            purchasePrice: $data->purchasePrice,
            saleDate: $on === null ? null : self::day($on),
            salePrice: $on === null ? null : '9000.000',
        );
    }
}
