<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IncidentRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The incidents as the prototype lays them out (Phase 33.3, spec.md §7.29):
 * the tab's strip and cards, the type icons, *Breakdown*, the restyled
 * incident page, the claims history's tiles, list and copy lines, and the
 * Mileage tab's link for an incident's reading.
 */
final class IncidentLayoutTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-29T10:00:00Z';

    /**
     * @param array<string, string|list<string>> $overrides
     * @return array<string, string|list<string>>
     */
    private static function form(array $overrides = []): array
    {
        return $overrides + [
            'occurred_on' => '2026-03-14',
            'type' => 'parked_damage',
            'fault' => 'not_at_fault',
            'location' => 'Tesco car park',
            'description' => 'Reversed into by a van while parked.',
            'damage_areas' => ['rear'],
            'severity' => 'minor',
            'write_off_category' => 'none',
            'claim_status' => 'settled',
            'insurer' => 'Aviva',
            'claim_number' => '4417',
            'excess' => '0',
            'payout' => '1000',
            'ncd_affected' => 'no',
            'status' => 'closed',
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<Incident>
     */
    private function incidents(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, IncidentRepository::class)->listForVehicle($vehicle->id);
    }

    /**
     * The owner's Golf with three incidents: a settled not-at-fault claim
     * (£1,000 paid) with a £1,400 repair linked, an at-fault collision
     * still open, and a pothole never claimed.
     *
     * @return array{0: App<ContainerInterface>, 1: Vehicle}
     */
    private function golfWithIncidents(): array
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/incidents/new';
        $browser->post($base, self::form());
        $browser->post($base, self::form([
            'occurred_on' => '2026-06-02',
            'type' => 'collision',
            'fault' => 'at_fault',
            'location' => 'A26 roundabout',
            'claim_status' => 'open',
            'claim_number' => 'AB-77',
            'payout' => '',
            'excess' => '250',
            'status' => 'open',
        ]));
        $browser->post($base, self::form([
            'occurred_on' => '2025-11-20',
            'type' => 'pothole',
            'fault' => 'unknown',
            'claim_status' => 'not_claimed',
            'insurer' => '',
            'claim_number' => '',
            'payout' => '',
            'excess' => '',
        ]));
        $parked = array_values(array_filter(
            $this->incidents($app, $golf),
            static fn (Incident $i): bool => $i->data->type === IncidentType::ParkedDamage,
        ))[0];
        $repair = $this->maintenance($app, $golf, '2026-03-20', 'Rear bumper', '1400.00');
        $this->service($app, IncidentRepository::class)->setLink(LinkKind::Maintenance, $golf->id, $repair->id, $parked->id);

        return [$app, $golf];
    }

    /**
     * The tab's strip as "label value sub" lines, tags and spacing removed.
     */
    private static function strip(string $page, string $open = '<dl class="stats">'): string
    {
        $start = strpos($page, $open);
        self::assertNotFalse($start, 'the strip');
        $html = (string) substr($page, $start, (int) strpos($page, '</dl>', $start) - $start);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('</div>', ' |', $html))));
    }

    /**
     * A claims history row's copy parts as its data attribute holds them.
     *
     * @param list<string> $parts
     */
    private static function line(array $parts): string
    {
        return htmlspecialchars((string) json_encode($parts));
    }

    public function testTheOwnerSeesEveryFigureOnTheStripAndTheCards(): void
    {
        [$app, $golf] = $this->golfWithIncidents();
        $browser = $this->browserFor($app, 'owner');
        $page = self::body($browser->get('/vehicles/' . $golf->id . '/incidents'));

        self::assertSame(
            'Incidents 3 | Claims 2 1 at fault | Insurer paid £1,000.00 | Net cost £400.00 |',
            self::strip($page),
        );
        self::assertSame(3, substr_count($page, 'class="incident-card"'));
        // Each card links to its page; the open one comes first.
        $card = '#<a class="incident-card__link" href="/vehicles/' . $golf->id . '/incidents/\d+">Collision</a>#';
        self::assertMatchesRegularExpression($card, $page);
        self::assertLessThan(strpos($page, '>Parked damage<'), strpos($page, '>Collision<'), 'open first');
        // The type's icon, the claim pill, the location and the description.
        self::assertStringContainsString('#car_crash"', $page);
        self::assertStringContainsString('#local_parking"', $page);
        self::assertStringContainsString('#warning"', $page);
        self::assertStringContainsString('Claim settled', $page);
        self::assertStringContainsString('Claim open', $page);
        self::assertStringContainsString('No claim', $page);
        self::assertStringContainsString('14 Mar 2026 · Tesco car park', $page);
        self::assertStringContainsString('Reversed into by a van while parked.', $page);
        self::assertStringContainsString('<dt>Insurer</dt><dd>Aviva</dd>', $page);
        self::assertStringContainsString('<dt>Net cost</dt><dd class="tabular">£400.00</dd>', $page);
    }

    public function testAViewerWithoutDetailsGetsNoFaultAndOnlyLinkedCosts(): void
    {
        [$app, $golf] = $this->golfWithIncidents();
        $viewer = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, true, false, new DateTimeImmutable(self::NOW));
        $page = self::body($this->browserFor($app, 'viewer')->get('/vehicles/' . $golf->id . '/incidents'));

        // No claim, fault or payout is visible, so the net cost is what is linked.
        self::assertSame(
            'Incidents 3 | Claims — Not shared with you | Insurer paid —'
            . ' | Net cost £1,400.00 Before payouts not shared with you |',
            self::strip($page),
        );
        self::assertStringNotContainsString('at fault', $page);
        self::assertStringNotContainsString('Tesco', $page, 'the location is a detail');
        self::assertStringNotContainsString('Reversed into', $page, 'the description is a detail');
        self::assertStringNotContainsString('Aviva', $page);
        self::assertStringNotContainsString('Claim settled', $page);
        self::assertStringContainsString('#car_crash"', $page, 'the type is the summary');
    }

    public function testALogShareSeesItsOwnClaimButNoAtFaultCount(): void
    {
        [$app, $golf] = $this->golfWithIncidents();
        $driver = $this->createMember($app, 'driver');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $driver->id, ShareLevel::Log, true, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'driver');
        $theirs->post('/vehicles/' . $golf->id . '/incidents/new', self::form([
            'occurred_on' => '2026-07-01',
            'type' => 'glass',
            'fault' => 'at_fault',
            'claim_status' => 'notified',
            'payout' => '',
        ]));

        $strip = self::strip(self::body($theirs->get('/vehicles/' . $golf->id . '/incidents')));
        self::assertStringStartsWith('Incidents 4 | Claims 1 |', $strip, 'only their own claim, and no fault count');
    }

    public function testWithoutViewCostsTheStripAndCardsHaveNoAmounts(): void
    {
        [$app, $golf] = $this->golfWithIncidents();
        $viewer = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));
        $page = self::body($this->browserFor($app, 'viewer')->get('/vehicles/' . $golf->id . '/incidents'));

        self::assertSame('Incidents 3 | Claims — Not shared with you |', self::strip($page));
        self::assertStringNotContainsString('£', $page);
        self::assertStringNotContainsString('Insurer paid', $page);
        self::assertStringNotContainsString('Linked costs', $page);
    }

    public function testABreakdownIsLoggedThroughTheFormWithItsIcon(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $form = self::body($browser->get('/vehicles/' . $golf->id . '/incidents/new'));
        self::assertStringContainsString('<option value="breakdown"', $form);

        $response = $browser->post('/vehicles/' . $golf->id . '/incidents/new', self::form([
            'type' => 'breakdown',
            'damage_areas' => [],
            'severity' => '',
            'claim_status' => 'not_claimed',
            'payout' => '',
        ]));
        self::assertSame(303, $response->getStatusCode());
        $incidents = $this->incidents($app, $golf);
        self::assertCount(1, $incidents);
        self::assertSame(IncidentType::Breakdown, $incidents[0]->data->type);

        $tab = self::body($browser->get('/vehicles/' . $golf->id . '/incidents'));
        self::assertStringContainsString('>Breakdown</a>', $tab);
        self::assertStringContainsString('#minor_crash"', $tab);
        $page = self::body($browser->get('/vehicles/' . $golf->id . '/incidents/' . $incidents[0]->id));
        self::assertStringContainsString('#minor_crash"', $page);
    }

    public function testTheIncidentPageKeepsItsActionsInTheNewLayout(): void
    {
        [$app, $golf] = $this->golfWithIncidents();
        $browser = $this->browserFor($app, 'owner');
        $parked = array_values(array_filter(
            $this->incidents($app, $golf),
            static fn (Incident $i): bool => $i->data->type === IncidentType::ParkedDamage,
        ))[0];
        $this->expense($app, $golf, '2026-03-16', '90.00');
        $base = '/vehicles/' . $golf->id . '/incidents/' . $parked->id;
        $page = self::body($browser->get($base));

        // The header: the type's tile, "date · location" and the claim pill.
        self::assertStringContainsString('incident-tile incident-tile--lg', $page);
        self::assertStringContainsString('#local_parking"', $page);
        self::assertStringContainsString('14 Mar 2026 · Tesco car park', $page);
        self::assertStringContainsString('<span class="pill pill--valid">Claim settled</span>', $page);
        // The description first, then the details as a grid.
        $description = strpos($page, 'Reversed into by a van while parked.');
        self::assertNotFalse($description);
        self::assertLessThan(strpos($page, 'Damaged areas'), $description);
        self::assertStringContainsString('<dl class="meta-grid">', $page);
        // Everything it did before.
        self::assertStringContainsString('href="' . $base . '/edit"', $page);
        self::assertStringContainsString('Add reminder', $page);
        self::assertStringContainsString('Add a repair', $page);
        self::assertStringContainsString('Add an expense', $page);
        self::assertStringContainsString('name="unlink"', $page);
        self::assertStringContainsString('Rear bumper', $page);
        self::assertStringContainsString('id="f-record"', $page, 'the link picker offers the hire car');
        self::assertStringContainsString('id="incident-costs"', $page);
        self::assertStringContainsString('£400.00', $page);
    }

    public function testTheClaimsHistoryShowsItsTilesAListAndTheCopyLines(): void
    {
        [$app, $golf] = $this->golfWithIncidents();
        $fiesta = $this->vehicle($app, 'Ford', 'Fiesta');
        $browser = $this->browserFor($app, 'owner');
        $browser->post('/vehicles/' . $fiesta->id . '/incidents/new', self::form([
            'occurred_on' => '2023-05-02',
            'type' => 'collision',
            'fault' => 'at_fault',
            'claim_number' => 'FX-1',
            'payout' => '500',
            'excess' => '250',
        ]));
        $page = self::body($browser->get('/incidents/history'));

        $tiles = self::strip($page, '<dl class="stats no-print">');
        // Three claims (the pothole was never claimed), the latest at-fault one this June.
        self::assertSame(
            'Claims in this period 3 2 at fault | Since last fault claim Under 1 yr 2 Jun 2026'
            . ' | Paid by insurers £1,500.00 | Excess paid £500.00 |',
            $tiles,
        );

        // On screen a list; the table only when printed; Copy only with JS.
        self::assertStringContainsString('<ul class="list claims-list no-print">', $page);
        self::assertStringContainsString('<div class="table-wrap print-only">', $page);
        self::assertStringContainsString('<th scope="col">Claim number</th>', $page);
        self::assertMatchesRegularExpression('/<button[^>]*data-claims-copy hidden>/', $page);
        self::assertStringContainsString('data-claims-copied="Copied"', $page);
        self::assertStringContainsString('js/claims-history.js', $page);
        self::assertStringContainsString(
            'data-claims-line="'
            . self::line(['14 Mar 2026', 'Parked damage', 'Not at fault', 'Claim settled', '£1,000.00', 'Volkswagen Golf']) . '"',
            $page,
        );
        self::assertStringContainsString(
            self::line(['20 Nov 2025', 'Pothole', 'Fault not known', 'No claim', '', 'Volkswagen Golf']),
            $page,
        );

        // The CSV is as before: the table's columns, never the tiles.
        $csv = self::body($browser->get('/incidents/history.csv'));
        self::assertStringContainsString('FX-1', $csv);
        self::assertStringNotContainsString('Since last fault claim', $csv);

        // Since the Fiesta's collision: whole years.
        $older = self::body($browser->get('/incidents/history?fault=at_fault&vehicle=' . $fiesta->id));
        self::assertStringContainsString('3 yrs', $older);
    }

    public function testTheClaimsHistoryTilesCountOnlyWhatAViewerMaySee(): void
    {
        [$app, $golf] = $this->golfWithIncidents();
        $viewer = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, true, false, new DateTimeImmutable(self::NOW));
        $page = self::body($this->browserFor($app, 'viewer')->get('/incidents/history'));

        self::assertStringContainsString('Claims in this period', $page);
        self::assertSame(
            'Claims in this period 0 none at fault | Since last fault claim — Not shared with you |',
            self::strip($page, '<dl class="stats no-print">'),
            'no "None" for faults the viewer can\'t see, and no amounts',
        );
        self::assertStringNotContainsString('Paid by insurers', $page);
        self::assertStringNotContainsString('Excess paid', $page);
        self::assertStringContainsString(
            self::line(['14 Mar 2026', 'Parked damage', 'Not shared with you', 'Volkswagen Golf']),
            $page,
        );
    }

    public function testTheMileageTabOpensAnIncidentReadingsIncident(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->post('/vehicles/' . $golf->id . '/incidents/new', self::form(['odometer' => '41250']));
        $incident = $this->incidents($app, $golf)[0];
        $readings = array_values(array_filter(
            $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id),
            static fn ($r): bool => $r->source === OdometerSource::Incident,
        ));
        self::assertCount(1, $readings);

        $link = '/vehicles/' . $golf->id . '/odometer/' . $readings[0]->id . '/edit';
        $mileage = self::body($browser->get('/vehicles/' . $golf->id . '/odometer'));
        self::assertStringContainsString('href="' . $link . '"', $mileage);
        $open = $browser->get($link);
        self::assertSame(303, $open->getStatusCode());
        self::assertSame('/vehicles/' . $golf->id . '/incidents/' . $incident->id . '/edit', $open->getHeaderLine('Location'));
    }
}
