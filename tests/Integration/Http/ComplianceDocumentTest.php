<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Compliance documents end to end. Create AND edit are both covered: editing
 * guards against the known "can't update a compliance entry" bug (spec.md
 * §7.5), so every edit here must change the same row, in place.
 */
final class ComplianceDocumentTest extends AppTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    private const array POLICY = [
        'type' => 'insurance',
        'title' => '',
        'provider' => 'Acme Insurance',
        'reference' => 'POL-123',
        'start_on' => '2025-10-10',
        'expiry_on' => '2026-10-09',
        'cost' => '412.50',
        'notes' => '',
    ];

    public function testCreateShowsTheDocumentWithItsExpiry(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/documents';

        $empty = self::body($browser->get($base));
        self::assertStringContainsString('No documents yet', $empty);
        self::assertStringContainsString('href="' . $base . '/new?type=insurance"', $empty);

        $form = self::body($browser->get($base . '/new?type=pollution'));
        self::assertStringContainsString('value="pollution" checked', $form);

        $created = $browser->post($base . '/new', self::POLICY);
        self::assertSame(303, $created->getStatusCode());
        self::assertSame($base, $created->getHeaderLine('Location'));
        $list = self::body($browser->follow($created));
        self::assertStringContainsString('The document was saved.', $list);
        self::assertStringContainsString('Acme Insurance', $list);
        self::assertStringContainsString('10 Oct 2025 – 9 Oct 2026', $list);
        self::assertStringContainsString('Expires in 12 days', $list);
        self::assertStringContainsString('£412.50', $list);

        $document = $this->onlyDocument($app, $golf);
        self::assertSame(ComplianceType::Insurance, $document->data->type);
        self::assertSame('2026-10-09', $document->data->expiryOn?->format('Y-m-d'));
        self::assertSame('412.500', $document->data->cost);

        $overview = self::body($browser->get('/vehicles/' . $golf->id));
        self::assertStringContainsString('Expires in 12 days', $overview, 'the overview shows where documents stand');
    }

    /**
     * Regression: editing an existing compliance document must work.
     */
    public function testEditUpdatesTheSameDocumentInPlace(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/documents';
        $browser->post($base . '/new', self::POLICY);
        $original = $this->onlyDocument($app, $golf);
        $editPath = $base . '/' . $original->id . '/edit';

        // The edit form is pre-filled and posts back to the document's own URL.
        $form = self::body($browser->get($editPath));
        self::assertStringContainsString('action="' . $editPath . '"', $form);
        self::assertStringContainsString('value="insurance" checked', $form);
        self::assertStringContainsString('value="Acme Insurance"', $form);
        self::assertStringContainsString('value="POL-123"', $form);
        self::assertStringContainsString('value="2025-10-10"', $form);
        self::assertStringContainsString('value="2026-10-09"', $form);
        self::assertStringContainsString('value="412.5"', $form);

        // Saving it unchanged works.
        $unchanged = $browser->post($editPath, self::POLICY);
        self::assertSame(303, $unchanged->getStatusCode());
        self::assertSame($base, $unchanged->getHeaderLine('Location'));
        self::assertStringContainsString('Changes to the document were saved.', self::body($browser->follow($unchanged)));

        // Changing every field updates the same row.
        $changed = [
            'type' => 'insurance',
            'title' => 'Fully comprehensive',
            'provider' => 'Better Insurance Ltd',
            'reference' => 'BI-9',
            'start_on' => '2025-10-10',
            'expiry_on' => '2027-01-31',
            'cost' => '0',
            'notes' => 'Includes breakdown cover',
        ];
        $response = $browser->post($editPath, $changed);
        self::assertSame(303, $response->getStatusCode());

        $edited = $this->onlyDocument($app, $golf);
        self::assertSame($original->id, $edited->id, 'the same document, not a new one');
        self::assertSame('Fully comprehensive', $edited->data->title);
        self::assertSame('Better Insurance Ltd', $edited->data->provider);
        self::assertSame('BI-9', $edited->data->reference);
        self::assertSame('2027-01-31', $edited->data->expiryOn?->format('Y-m-d'));
        self::assertSame('0.000', $edited->data->cost, 'a cost of 0 is valid on edit too');
        self::assertSame('Includes breakdown cover', $edited->data->notes);
        self::assertEquals($original->createdAt, $edited->createdAt);

        // Clearing optional fields is an edit too.
        $browser->post($editPath, ['title' => '', 'reference' => '', 'expiry_on' => '', 'notes' => ''] + $changed);
        $cleared = $this->onlyDocument($app, $golf);
        self::assertNull($cleared->data->title);
        self::assertNull($cleared->data->reference);
        self::assertNull($cleared->data->expiryOn);
        self::assertNull($cleared->data->notes);

        $list = self::body($browser->get($base));
        self::assertStringContainsString('No expiry', $list);
    }

    public function testAnInvalidEditChangesNothing(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/documents';
        $browser->post($base . '/new', self::POLICY);
        $original = $this->onlyDocument($app, $golf);

        $response = $browser->post($base . '/' . $original->id . '/edit', ['expiry_on' => '2025-01-01'] + self::POLICY);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('The expiry date cannot be before the start date.', self::body($response));
        self::assertStringContainsString('value="2025-01-01"', self::body($response), 'input is kept');
        self::assertEquals($original, $this->onlyDocument($app, $golf));
    }

    public function testRenewingReplacesTheOldDocumentAndDeleteRemovesOne(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/documents';
        $browser->post($base . '/new', self::POLICY);

        $list = self::body($browser->get($base));
        self::assertStringContainsString('href="' . $base . '/new?type=insurance"', $list, 'a Renew button');

        $renewal = ['start_on' => '2026-10-10', 'expiry_on' => '2027-10-09', 'reference' => 'POL-124'];
        $browser->post($base . '/new', $renewal + self::POLICY);
        $renewed = self::body($browser->get($base));
        self::assertStringContainsString('Starts 10 Oct 2026', $renewed);
        self::assertStringContainsString('1 earlier document', $renewed);
        self::assertStringContainsString('Replaced', $renewed);
        self::assertStringNotContainsString('Expires in 12 days', $renewed, 'the renewed policy no longer calls for attention');

        [$old] = $this->documents($app, $golf);
        self::assertStringContainsString('Delete Insurance?', self::body($browser->get($base . '/' . $old->id . '/delete')));
        $deleted = $browser->post($base . '/' . $old->id . '/delete');
        self::assertSame($base, $deleted->getHeaderLine('Location'));
        self::assertCount(1, $this->documents($app, $golf));
        self::assertSame(404, $browser->get($base . '/' . $old->id . '/edit')->getStatusCode());
    }

    public function testDocumentsOfAnotherVehicleAreNotReachable(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $bike = $this->vehicle($app);
        $browser->post('/vehicles/' . $golf->id . '/documents/new', self::POLICY);
        $document = $this->onlyDocument($app, $golf);

        $path = '/vehicles/' . $bike->id . '/documents/' . $document->id . '/edit';
        self::assertSame(404, $browser->get($path)->getStatusCode());
        self::assertSame(404, $browser->post($path, ['provider' => 'Hijack'] + self::POLICY)->getStatusCode());
        self::assertSame('Acme Insurance', $this->onlyDocument($app, $golf)->data->provider);
    }

    public function testWorksBehindASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->post('/logbook/vehicles/' . $golf->id . '/documents/new', self::POLICY);
        $document = $this->onlyDocument($app, $golf);

        // Hard refresh of the edit page with the prefix stripped by the proxy.
        $path = '/vehicles/' . $golf->id . '/documents/' . $document->id . '/edit';
        $edit = self::body($browser->get($path));
        self::assertStringContainsString('action="/logbook' . $path . '"', $edit);

        $saved = $browser->post('/logbook' . $path, ['provider' => 'Moved Ltd'] + self::POLICY);
        self::assertSame('/logbook/vehicles/' . $golf->id . '/documents', $saved->getHeaderLine('Location'));
        self::assertSame('Moved Ltd', $this->onlyDocument($app, $golf)->data->provider);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function vehicle(App $app): Vehicle
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $this->service($app, VehicleService::class)
            ->create($owner, new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol));
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<ComplianceDocument>
     */
    private function documents(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($vehicle->id);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function onlyDocument(App $app, Vehicle $vehicle): ComplianceDocument
    {
        $documents = $this->documents($app, $vehicle);
        self::assertCount(1, $documents);

        return $documents[0];
    }
}
