<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Scan;

use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Incident\IncidentService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\ScanFixture;
use Logbook\Tests\Support\ScanTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Reading claim letters and repair estimates (spec.md §7.27, §7.29, Phase
 * 27.2): a letter updates the incident with its claim number, its changed
 * fields marked and the letter attached on save; no match opens *Log
 * incident*; *Update from a letter* needs no match; an estimate goes on the
 * latest open incident (or another, or a new one) and counts nothing.
 */
final class ClaimScanTest extends ScanTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->scanApp();
    }

    /**
     * Acceptance 2: the insurer's settlement letter updates the open
     * incident with the same claim number to *settled*, with the payout,
     * and the letter attached.
     */
    public function testASettlementLetterUpdatesTheIncidentWithTheSameClaimNumber(): void
    {
        $incident = $this->log('2026-09-01', new Claim(
            ClaimStatus::Open,
            'Harbourside Insurance plc',
            claimNumber: 'hsc 55102',
            excess: '250.000',
        ));
        $golf = $this->garage['Golf'];

        [$page, $path] = $this->scanFixture('22-settlement-cat-s');
        $edit = '/vehicles/' . $golf->id . '/incidents/' . $incident->id . '/edit?scan=';
        self::assertStringStartsWith($edit, (string) end($path), 'the matching incident\'s edit form');
        $html = self::body($page);
        $values = self::values($html);
        self::assertSame('settled', $values['claim_status']);
        self::assertSame('9000', $values['payout']);
        self::assertSame('cat_s', $values['write_off_category']);
        self::assertSame('2026-10-12', $values['claim_updated_on'], 'the letter date is the latest update');
        self::assertSame('2026-09-01', $values['occurred_on'], 'the incident keeps its own date');
        self::assertTrue(self::marked($html, 'payout'));
        self::assertTrue(self::marked($html, 'claim_status'));
        self::assertFalse(self::marked($html, 'insurer'), 'unchanged, so not marked');
        self::assertFalse(self::marked($html, 'excess'), 'the same £250');

        $saved = $this->save($golf->id, $incident->id, $values);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        $after = $this->incident($incident->id);
        self::assertSame(ClaimStatus::Settled, $after->data->claim->status);
        self::assertSame('9000.000', $after->data->claim->payout);
        self::assertSame(WriteOffCategory::CatS, $after->data->writeOff);
        self::assertSame('2026-10-12', $after->data->claim->updatedOn?->format('Y-m-d'));
        $files = $this->service($this->app, AttachmentRepository::class)
            ->listForOwner($golf->id, AttachmentOwner::Incident, $incident->id);
        self::assertCount(1, $files, 'the letter is attached');
        self::assertSame('letter.pdf', $files[0]->filename);
    }

    public function testALetterWithNoMatchingClaimOpensLogIncident(): void
    {
        $this->log('2026-09-01', new Claim(ClaimStatus::Open, 'Harbourside Insurance plc', claimNumber: 'HSC-99999'));

        [$page, $path] = $this->scanFixture('21-claim-letter');

        self::assertStringStartsWith('/vehicles/' . $this->garage['Golf']->id . '/incidents/new?scan=', (string) end($path));
        $values = self::values(self::body($page));
        self::assertSame('2026-09-02', $values['occurred_on'], 'the incident date, when creating');
        self::assertSame('HSC-55102', $values['claim_number']);
        self::assertSame('not_claimed', $values['claim_status'], '"under review" is unclear, so left alone');
    }

    public function testUpdateFromALetterNeedsNoMatch(): void
    {
        $incident = $this->log('2026-09-01', new Claim(ClaimStatus::Notified));
        $golf = $this->garage['Golf'];
        $page = self::body($this->browser->get('/vehicles/' . $golf->id . '/incidents/' . $incident->id));
        $link = '/scan?vehicle=' . $golf->id . '&amp;for=incident&amp;incident=' . $incident->id;
        self::assertStringContainsString($link, $page);
        self::assertStringContainsString('Update from a letter', $page);

        $fixture = ScanFixture::load('21-claim-letter');
        $this->reply(self::without($fixture->reply, 'claim_number'));
        [$form, $path] = $this->land($this->scan($fixture->bytes(), 'letter.pdf', 'application/pdf', [
            'vehicle_id' => (string) $golf->id,
            'for' => 'incident',
            'incident' => (string) $incident->id,
        ]));

        $edit = '/vehicles/' . $golf->id . '/incidents/' . $incident->id . '/edit?scan=';
        self::assertStringStartsWith($edit, (string) end($path));
        self::assertSame('Harbourside Insurance plc', self::values(self::body($form))['insurer']);
    }

    public function testAnotherVehiclesIncidentIsNeverScannedInto(): void
    {
        $incident = $this->log('2026-09-01', new Claim(ClaimStatus::Notified));
        $fixture = ScanFixture::load('21-claim-letter');
        $this->reply(self::without($fixture->reply, 'claim_number'));
        [, $path] = $this->land($this->scan($fixture->bytes(), 'letter.pdf', 'application/pdf', [
            'vehicle_id' => (string) $this->garage['BMW']->id,
            'for' => 'incident',
            'incident' => (string) $incident->id,
        ]));

        $last = (string) end($path);
        self::assertStringNotContainsString('/incidents/' . $incident->id . '/edit', $last, 'not the BMW\'s incident');
    }

    public function testAnEstimateGoesOnTheLatestOpenIncidentAndChangesNoCost(): void
    {
        $older = $this->log('2026-08-01', new Claim(), notes: null);
        $latest = $this->log('2026-09-20', new Claim(), notes: 'Bumper scraped in the car park');
        $this->log('2026-09-25', new Claim(), status: IncidentStatus::Closed);
        $golf = $this->garage['Golf'];

        [$page, $path] = $this->scanFixture('23-estimate-photo', 'estimate.jpg', 'image/jpeg');
        self::assertStringStartsWith('/vehicles/' . $golf->id . '/incidents/' . $latest->id . '/edit?scan=', (string) end($path));
        $html = self::body($page);
        $values = self::values($html);
        self::assertSame('1284', $values['repair_estimate']);
        self::assertSame("Bumper scraped in the car park\nEstimate from Coastline Body Repairs", $values['notes']);
        self::assertStringContainsString('Which incident is this estimate for?', $html);
        self::assertStringContainsString('A new incident', $html);

        // Another incident, or a new one, from the choice (a plain GET form).
        $token = (string) preg_replace('#^.*[?&]scan=([a-f0-9]{32}).*$#', '$1', (string) end($path));
        $query = '/scan/' . $token . '?vehicle=' . $golf->id . '&as=repair_estimate&incident=';
        [, $toOlder] = $this->land($this->browser->get($query . $older->id));
        $olderEdit = '/vehicles/' . $golf->id . '/incidents/' . $older->id . '/edit?scan=';
        self::assertStringStartsWith($olderEdit, (string) end($toOlder));
        [, $toNew] = $this->land($this->browser->get($query . 'new'));
        self::assertStringStartsWith('/vehicles/' . $golf->id . '/incidents/new?scan=', (string) end($toNew));

        $saved = $this->save($golf->id, $latest->id, $values);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        $after = $this->incident($latest->id);
        self::assertSame('1284.000', $after->data->claim->repairEstimate);
        $costs = $this->service($this->app, IncidentService::class)->costs($this->owner, $golf, $after);
        self::assertSame('0.00', $costs->linked->toDecimal(2), 'an estimate is not a cost');
        $spent = $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM maintenance_entries');
        self::assertSame(0, (int) (is_numeric($spent) ? $spent : 0));
    }

    public function testAnEstimateWithNoOpenIncidentOpensLogIncident(): void
    {
        $this->log('2026-09-25', new Claim(), status: IncidentStatus::Closed);

        [, $path] = $this->scanFixture('23-estimate-photo', 'estimate.jpg', 'image/jpeg');

        self::assertStringStartsWith('/vehicles/' . $this->garage['Golf']->id . '/incidents/new?scan=', (string) end($path));
    }

    public function testWithTheIncidentsModuleOffThereAreNoIncidentKinds(): void
    {
        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Incidents)));

        [$page] = $this->scanFixture('22-settlement-cat-s');
        self::assertStringContainsString('is switched off, so there is no form for this', self::body($page));

        $invoice = ScanFixture::load('02-service-invoice-photo');
        $this->reply($invoice->reply);
        [$form] = $this->land($this->scan($invoice->bytes(), 'invoice.jpg', 'image/jpeg'));
        self::assertStringNotContainsString('as=claim_letter', self::body($form), '*Read it as* leaves them out');
        self::assertStringNotContainsString('as=repair_estimate', self::body($form));
    }

    public function testWithReadingFilesOffThereIsNoUpdateFromALetter(): void
    {
        $incident = $this->log('2026-09-01', new Claim(ClaimStatus::Notified));
        $golf = $this->garage['Golf'];
        $toggles = $this->service($this->app, FeatureToggles::class);
        $on = [];
        foreach (Feature::cases() as $feature) {
            if ($feature !== Feature::AiScan) {
                $on[] = $feature;
            }
        }
        $toggles->save($on);

        $page = self::body($this->browser->get('/vehicles/' . $golf->id . '/incidents/' . $incident->id));
        self::assertStringNotContainsString('Update from a letter', $page);
        $form = self::body($this->browser->get('/vehicles/' . $golf->id . '/incidents/new'));
        self::assertStringNotContainsString('Fill from a file', $form);
    }

    public function testTheIncidentKindsAreOfferedAsReadItAs(): void
    {
        $invoice = ScanFixture::load('02-service-invoice-photo');
        $this->reply($invoice->reply);
        [$form] = $this->land($this->scan($invoice->bytes(), 'invoice.jpg', 'image/jpeg'));

        self::assertStringContainsString('as=claim_letter', self::body($form));
        self::assertStringContainsString('Insurance claim letter', self::body($form));
    }

    /**
     * @return array{ResponseInterface, list<string>}
     */
    private function scanFixture(string $name, string $file = 'letter.pdf', string $mime = 'application/pdf'): array
    {
        $fixture = ScanFixture::load($name);
        $this->reply($fixture->reply);
        [$page, $path] = $this->land($this->scan($fixture->bytes(), $file, $mime));
        self::assertSame(200, $page->getStatusCode(), implode(' → ', $path));

        return [$page, $path];
    }

    /**
     * @param array<string, string> $values the edit form as a browser would send it
     */
    private function save(int $vehicleId, int $incidentId, array $values): ResponseInterface
    {
        $fields = $values;
        unset($fields['damage_areas[]'], $fields['_csrf_name'], $fields['_csrf_value']);
        $fields['damage_areas'] = [DamageArea::Rear->value];

        return $this->browser->post('/vehicles/' . $vehicleId . '/incidents/' . $incidentId . '/edit', $fields);
    }

    /**
     * @return array<string, string>
     */
    private static function values(string $html): array
    {
        return Html::formValues(Html::element(Html::document($html), '#incident-form'));
    }

    private static function marked(string $html, string $field): bool
    {
        $label = Html::element(Html::document($html), 'label[for="f-' . $field . '"]');

        return $label->querySelector('.field__from-file') !== null;
    }

    /**
     * @param array<string, mixed> $reply
     * @return array<string, mixed>
     */
    private static function without(array $reply, string $field): array
    {
        $fields = is_array($reply['fields'] ?? null) ? $reply['fields'] : [];
        unset($fields[$field]);

        return ['fields' => $fields] + $reply;
    }

    private function incident(int $id): Incident
    {
        $incident = $this->service($this->app, IncidentRepository::class)->find($this->garage['Golf']->id, $id);
        self::assertNotNull($incident);

        return $incident;
    }

    private function log(
        string $date,
        Claim $claim,
        IncidentStatus $status = IncidentStatus::Open,
        ?string $notes = null,
    ): Incident {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);

        return $this->service($this->app, IncidentService::class)->create($this->garage['Golf'], new IncidentData(
            occurredOn: $day,
            type: IncidentType::ParkedDamage,
            damageAreas: [DamageArea::Rear],
            status: $status,
            closedOn: $status === IncidentStatus::Closed ? $day : null,
            notes: $notes,
            claim: $claim,
        ), null, new DateTimeZone('Europe/London'));
    }
}
