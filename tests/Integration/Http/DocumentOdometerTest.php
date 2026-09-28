<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * A document's odometer (spec.md §7.5): the reading on an MOT certificate
 * joins the mileage series as a `document` reading at local noon on its
 * start date, moves and goes with the document, and needs a start date. The
 * owner uses miles in Europe/London.
 */
final class DocumentOdometerTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    private const array MOT = [
        'type' => 'inspection',
        'title' => '',
        'provider' => 'Main Street Motors',
        'reference' => 'MOT-1',
        'start_on' => '2026-09-01',
        'expiry_on' => '2027-08-31',
        'cost' => '54.85',
        'odometer' => '30000',
        'notes' => '',
    ];

    public function testTheReadingIsCreatedMovedAndRemovedWithItsDocument(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/documents';
        $readings = $this->service($app, OdometerReadingRepository::class);

        $form = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('The reading on the certificate, if it shows one (an MOT certificate does).', $form);

        $created = $browser->post($base . '/new', self::MOT);
        self::assertSame(303, $created->getStatusCode());
        $list = self::body($browser->follow($created));
        self::assertStringContainsString('Odometer 30,000 mi', $list, 'the documents list shows it');

        $document = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($golf->id)[0];
        self::assertSame('48280.320', $document->data->odometerKm);
        $reading = $readings->findByEntry($golf->id, OdometerSource::Document, $document->id);
        self::assertNotNull($reading, 'the document writes a reading');
        self::assertSame('48280.320', $reading->readingKm);
        self::assertSame('2026-09-01 11:00:00', $reading->recordedAt->format('Y-m-d H:i:s'), 'noon BST on the start date');
        self::assertSame($document->id, $reading->complianceDocumentId);

        $mileage = self::body($browser->get('/vehicles/' . $golf->id . '/odometer'));
        self::assertStringContainsString('>Document</span>', $mileage, 'the Mileage tab names its source');
        $owner = $browser->get('/vehicles/' . $golf->id . '/odometer/' . $reading->id . '/edit');
        self::assertSame($base . '/' . $document->id . '/edit', $owner->getHeaderLine('Location'), 'edited through its document');
        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/odometer/' . $reading->id . '/delete')->getStatusCode());

        // Editing shows it in miles and moves the reading with the dates.
        $editPath = $base . '/' . $document->id . '/edit';
        self::assertStringContainsString('value="30000"', self::body($browser->get($editPath)));
        $browser->post($editPath, ['start_on' => '2026-09-02', 'odometer' => '30010'] + self::MOT);
        $moved = $readings->findByEntry($golf->id, OdometerSource::Document, $document->id);
        self::assertSame($reading->id, $moved?->id, 'the same reading, moved');
        self::assertSame('2026-09-02 11:00', $moved->recordedAt->format('Y-m-d H:i'));
        self::assertSame('48296.413', $moved->readingKm);

        // Clearing the odometer removes the reading; the document stays.
        $browser->post($editPath, ['odometer' => ''] + self::MOT);
        self::assertNull($readings->findByEntry($golf->id, OdometerSource::Document, $document->id));
        self::assertCount(1, $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($golf->id));

        // Deleting the document deletes its reading.
        $browser->post($editPath, self::MOT);
        self::assertCount(1, $readings->listForVehicle($golf->id));
        $browser->post($base . '/' . $document->id . '/delete');
        self::assertSame([], $readings->listForVehicle($golf->id));
    }

    public function testTheOdometerNeedsAStartDateAndRenewingNeverCopiesIt(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/documents';

        $refused = $browser->post($base . '/new', ['start_on' => ''] + self::MOT);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Add the date it was issued to record the odometer.', self::body($refused));
        self::assertStringContainsString('value="30000"', self::body($refused), 'the typed values are kept');
        self::assertSame([], $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($golf->id));
        self::assertSame([], $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id));

        // Without an odometer a start date is not needed.
        $saved = $browser->post($base . '/new', ['start_on' => '', 'odometer' => ''] + self::MOT);
        self::assertSame(303, $saved->getStatusCode());

        $browser->post($base . '/new', self::MOT);
        $renew = self::body($browser->get($base . '/new?type=inspection'));
        self::assertStringContainsString('name="odometer"', $renew);
        self::assertStringNotContainsString('value="30000"', $renew, 'renewing starts without the old odometer');
    }

    public function testAnImplausibleReadingWarnsButIsSaved(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->reading($app, $golf, '64373.760', '2026-08-01T09:00:00Z');

        $saved = $browser->post('/vehicles/' . $golf->id . '/documents/new', self::MOT);
        self::assertSame(303, $saved->getStatusCode(), 'never blocked');
        $page = self::body($browser->follow($saved));
        self::assertStringContainsString('Saved, but this reading is lower than the one before it', $page);
        self::assertCount(2, $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id));
    }
}
