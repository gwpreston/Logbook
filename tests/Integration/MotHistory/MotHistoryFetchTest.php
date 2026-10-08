<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\MotHistory\RecallState;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Repository\MotTestRepository;
use Logbook\Repository\OdometerReadingRepository;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fetching MOT history (spec.md §7.38 *Fetching*, *Mileage*): only with the
 * provider on, only by the owner (#321), only after their confirmation;
 * by registration, then VIN; refused when the make disagrees (#336);
 * upserted without duplicates; every read odometer a `mot` reading (#322);
 * *Stop and remove* takes the tests and readings only.
 */
final class MotHistoryFetchTest extends MotHistoryTestCase
{
    public function testWithTheProviderOffNothingAppearsOrIsSent(): void
    {
        $this->start(enable: false);
        $golf = $this->golf();
        $browser = $this->browserFor($this->app, 'owner');

        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/mot-history')->getStatusCode());
        self::assertSame(404, $browser->post('/vehicles/' . $golf->id . '/mot-history/fetch', ['confirm' => '1'])->getStatusCode());
        self::assertStringNotContainsString('data-mot-history-link', (string) $browser->get('/vehicles/' . $golf->id)->getBody());
        self::assertStringNotContainsString('data-mot-history-link', (string) $browser->get('/vehicles/' . $golf->id . '/documents')->getBody());
        self::assertSame([], $this->requests);
    }

    public function testNothingIsSentBeforeTheOwnersConfirmation(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->browserFor($this->app, 'owner');

        $page = (string) $browser->get('/vehicles/' . $golf->id . '/mot-history')->getBody();
        self::assertStringContainsString('Sends this vehicle&#039;s registration (or VIN) to DVSA (UK). Nothing else is sent.', $page);
        $browser->post('/vehicles/' . $golf->id . '/mot-history/fetch', []);

        self::assertSame([], $this->requests);
        self::assertStringContainsString('Tick the box to confirm', (string) $browser->get('/vehicles/' . $golf->id . '/mot-history')->getBody());
    }

    public function testOnlyTheOwnerMayFetch(): void
    {
        $this->start();
        $golf = $this->golf();
        $manager = $this->shareWith($golf, ShareLevel::Manage);

        self::assertSame(200, $manager->get('/vehicles/' . $golf->id . '/mot-history')->getStatusCode());
        self::assertStringNotContainsString('mot-history/fetch', (string) $manager->get('/vehicles/' . $golf->id . '/mot-history')->getBody());
        self::assertContains(
            $manager->post('/vehicles/' . $golf->id . '/mot-history/fetch', ['confirm' => '1'])->getStatusCode(),
            [403, 404],
        );
        self::assertSame([], $this->requests);
    }

    public function testAFetchStoresTestsReadingsAndTheRecallState(): void
    {
        $this->start();
        $golf = $this->golf();

        $response = $this->fetch($golf)->post('/vehicles/' . $golf->id . '/mot-history/fetch', []);

        self::assertSame(['https://history.mot.api.gov.uk/v1/trade/vehicles/registration/AB12CDE'], array_slice($this->vehicleRequests(), 0, 1));
        $tests = $this->service($this->app, MotTestRepository::class)->listForVehicle($golf->id);
        self::assertCount(5, $tests, 'a refresh adds no duplicates');
        self::assertSame('323456789012', $tests[0]->number);
        self::assertCount(8, $tests[1]->defects);
        $state = $this->service($this->app, MotTestRepository::class)->state($golf->id);
        self::assertSame(RecallState::Yes, $state->recall);
        self::assertNotNull($state->enabledAt);
        self::assertNull($state->firstDueOn, 'only for a vehicle with no tests');

        $readings = array_values(array_filter(
            $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($golf->id),
            static fn ($reading): bool => $reading->source === OdometerSource::Mot,
        ));
        // Four tests had a read odometer, the fail among them (#322); the unreadable one none.
        self::assertCount(4, $readings);
        self::assertSame(303, $response->getStatusCode());

        $page = (string) $this->browserFor($this->app, 'owner')->get('/vehicles/' . $golf->id . '/mot-history')->getBody();
        self::assertStringContainsString('An outstanding recall.', $page);
        self::assertStringContainsString('Odometer not read', $page);
        self::assertStringContainsString('(tested in km)', $page);
        self::assertStringContainsString('Contains public sector information licensed under the', $page);
        self::assertStringContainsString('Tester&#039;s note', $page);
    }

    public function testTheFetchSaysWhatHappened(): void
    {
        $this->start();
        // The make agrees (VW is Volkswagen); the model reads differently, which is only noted (#336).
        $golf = $this->golf(model: 'Polo');
        $browser = $this->fetch($golf);

        $page = (string) $browser->get('/vehicles/' . $golf->id . '/mot-history/review')->getBody();
        self::assertStringContainsString('5 new tests.', $page);
        self::assertStringContainsString('1 test without a date was not stored.', $page);
        self::assertStringContainsString('DVSA lists it as a GOLF MATCH TSI.', $page, 'a different model is a note');
        self::assertCount(5, $this->service($this->app, MotTestRepository::class)->listForVehicle($golf->id));
    }

    public function testAMismatchedMakeIsRefusedAndNothingStored(): void
    {
        $this->start();
        $fiesta = $this->golf(make: 'Ford', model: 'Fiesta');
        $browser = $this->fetch($fiesta);

        self::assertSame([], $this->service($this->app, MotTestRepository::class)->listForVehicle($fiesta->id));
        self::assertNull($this->service($this->app, MotTestRepository::class)->state($fiesta->id)->recall);
        $page = (string) $browser->get('/vehicles/' . $fiesta->id . '/mot-history')->getBody();
        self::assertStringContainsString('DVSA&#039;s record for AB12 CDE is a VOLKSWAGEN GOLF MATCH TSI; this vehicle is a Ford Fiesta.', $page);
    }

    public function testNoRecordByRegistrationTriesTheVinAndNamesThePlate(): void
    {
        $this->start();
        $this->answer = static fn (string $url): ?MockResponse => str_contains($url, '/registration/')
            ? new MockResponse((string) file_get_contents(self::FIXTURES . 'not-found.json'), ['http_code' => 404])
            : null;
        $golf = $this->golf('PR1 VAT', 'WVWZZZ1KZCW123456');
        $browser = $this->fetch($golf);

        self::assertSame([
            'https://history.mot.api.gov.uk/v1/trade/vehicles/registration/PR1VAT',
            'https://history.mot.api.gov.uk/v1/trade/vehicles/vin/WVWZZZ1KZCW123456',
        ], $this->vehicleRequests());
        self::assertCount(5, $this->service($this->app, MotTestRepository::class)->listForVehicle($golf->id));
        $page = (string) $browser->get('/vehicles/' . $golf->id . '/mot-history/review')->getBody();
        self::assertStringContainsString('DVSA knows this vehicle as AB12CDE.', $page);
    }

    public function testNoRecordAtAll(): void
    {
        $this->start();
        $this->answer = static fn (): MockResponse => new MockResponse('{}', ['http_code' => 404]);
        $golf = $this->golf();
        $browser = $this->fetch($golf);

        self::assertStringContainsString('No DVSA record for AB12 CDE.', (string) $browser->get('/vehicles/' . $golf->id . '/mot-history')->getBody());
        self::assertSame([], $this->service($this->app, MotTestRepository::class)->listForVehicle($golf->id));
    }

    public function testAFailureIsShownAndNothingStored(): void
    {
        $this->start();
        $this->answer = static fn (): MockResponse => new MockResponse('{}', ['http_code' => 429]);
        $golf = $this->golf();
        $browser = $this->fetch($golf);

        self::assertStringContainsString('DVSA is busy; try again later.', (string) $browser->get('/vehicles/' . $golf->id . '/mot-history')->getBody());
        self::assertSame([], $this->service($this->app, MotTestRepository::class)->listForVehicle($golf->id));
    }

    public function testAVehicleWithNoRegistrationOrVinSendsNothing(): void
    {
        $this->start();
        $golf = $this->golf('');
        $browser = $this->browserFor($this->app, 'owner');

        self::assertStringContainsString('Add a registration or VIN', (string) $browser->get('/vehicles/' . $golf->id . '/mot-history')->getBody());
        $this->fetch($golf, $browser);
        self::assertSame([], $this->vehicleRequests());
    }

    public function testStopAndRemoveTakesTheTestsAndReadingsOnly(): void
    {
        $this->start();
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        $review = $this->service($this->app, MotTestRepository::class)->listForVehicle($golf->id);
        $advisory = $review[2]->defects[0];
        $browser->post('/vehicles/' . $golf->id . '/mot-history/review', ['do' => 'issue', 'defect' => (string) $advisory->id]);
        $browser->post('/vehicles/' . $golf->id . '/mot-history/review', ['do' => 'document', 'test' => (string) $review[0]->id]);

        $browser->post('/vehicles/' . $golf->id . '/mot-history/stop', []);

        self::assertSame([], $this->service($this->app, MotTestRepository::class)->listForVehicle($golf->id));
        self::assertFalse($this->service($this->app, MotTestRepository::class)->state($golf->id)->enabled());
        $sources = array_map(
            static fn ($reading): string => $reading->source->value,
            $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($golf->id),
        );
        self::assertNotContains('mot', $sources);
        $connection = $this->connection($this->app);
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM issues WHERE vehicle_id = ?', [$golf->id]));
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM compliance_documents WHERE vehicle_id = ?', [$golf->id]));
    }
}
