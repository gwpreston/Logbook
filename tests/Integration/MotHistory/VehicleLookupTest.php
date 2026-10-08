<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use Logbook\Domain\Vehicle\FuelType;
use Logbook\Service\MotHistory\VehicleLookup;
use Dom\HTMLDocument;
use Logbook\Tests\Support\Html;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * *Look up* on the add-vehicle form (spec.md §7.38, #326, #330, #336):
 * only while the provider is on; fills only blank fields (no colour);
 * stores nothing and enables nothing; works with and without JS.
 */
final class VehicleLookupTest extends MotHistoryTestCase
{
    public function testNoButtonWhileTheProviderIsOff(): void
    {
        $this->start(enable: false);
        $browser = $this->browserFor($this->app, 'owner');

        self::assertStringNotContainsString('data-vehicle-lookup', (string) $browser->get('/vehicles/new')->getBody());
        // A complete form with lookup=1 (MOT history switched off after the page loaded) is never saved.
        $response = $browser->post('/vehicles/new', [
            'type' => 'car',
            'make' => 'Volkswagen',
            'model' => 'Golf',
            'fuel_type' => 'petrol',
            'registration' => 'AB12 CDE',
            'lookup' => '1',
        ]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('MOT history is off.', (string) $response->getBody());
        self::assertSame(0, $this->vehicles());
        self::assertSame([], $this->requests);
    }

    public function testWithoutJsTheFormComesBackWithTheBlankFieldsFilled(): void
    {
        $this->start();
        $browser = $this->browserFor($this->app, 'owner');
        $form = (string) $browser->get('/vehicles/new')->getBody();
        self::assertStringContainsString('Sends this registration to DVSA.', $form);
        // Enter saves: the first submit button is the hidden Save, not Look up.
        $document = Html::document($form);
        $first = $document->querySelector('form.form button[type="submit"]');
        self::assertNotNull($first);
        self::assertNotSame('lookup', $first->getAttribute('name'));

        $response = $browser->post('/vehicles/new', [
            'type' => 'car',
            'make' => '',
            'model' => 'My Golf',
            'fuel_type' => 'petrol',
            'registration' => 'ab12 cde',
            'lookup' => '1',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $document = Html::document((string) $response->getBody());
        self::assertSame('Volkswagen', $this->value($document, 'make'));
        self::assertSame('My Golf', $this->value($document, 'model'), 'a typed field is kept');
        self::assertSame('2012-03-14', $this->value($document, 'first_registered_on'));
        self::assertStringContainsString('Filled 2 fields from DVSA.', (string) $response->getBody());
        self::assertSame(0, $this->vehicles(), 'nothing is stored');
    }

    public function testWithJsTheAnswerIsJson(): void
    {
        $this->start();
        $this->answer = static fn (): MockResponse => new MockResponse(
            (string) file_get_contents(self::FIXTURES . 'new-vehicle.json'),
        );
        $browser = $this->browserFor($this->app, 'owner');

        $response = $browser->post(
            '/vehicles/new',
            ['make' => '', 'model' => '', 'fuel_type' => 'petrol', 'registration' => 'XY25ABC', 'lookup' => '1'],
            [],
            true,
            ['X-Lookup' => '1'],
        );

        $body = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame([
            'make' => 'Ford',
            'model' => 'Puma',
            'fuel_type' => 'hybrid',
            'first_registered_on' => '2025-03-14',
            'first_inspection_due_on' => '2028-03-13',
        ], $body['fields'] ?? null);
        self::assertSame('Filled 5 fields from DVSA. Check them before saving.', $body['message'] ?? null);
        self::assertSame(0, $this->vehicles());
    }

    public function testNoRecordAndNoRegistration(): void
    {
        $this->start();
        $this->answer = static fn (): MockResponse => new MockResponse('{}', ['http_code' => 404]);
        $browser = $this->browserFor($this->app, 'owner');

        $none = $browser->post('/vehicles/new', ['registration' => 'ZZ99 ZZZ', 'lookup' => '1'], [], true, ['X-Lookup' => '1']);
        self::assertStringContainsString('No DVSA record for ZZ99 ZZZ.', (string) $none->getBody());

        $this->requests = [];
        $blank = $browser->post('/vehicles/new', ['registration' => '', 'lookup' => '1'], [], true, ['X-Lookup' => '1']);
        self::assertStringContainsString('Type a registration to look up first.', (string) $blank->getBody());
        self::assertSame([], $this->requests);
    }

    public function testSavingAfterALookUpEnablesNothing(): void
    {
        $this->start();
        $browser = $this->browserFor($this->app, 'owner');
        $browser->post('/vehicles/new', ['registration' => 'AB12CDE', 'lookup' => '1'], [], true, ['X-Lookup' => '1']);

        $browser->post('/vehicles/new', [
            'type' => 'car',
            'make' => 'Volkswagen',
            'model' => 'Golf',
            'fuel_type' => 'petrol',
            'registration' => 'AB12CDE',
        ]);

        self::assertSame(1, $this->vehicles());
        $enabled = $this->connection($this->app)->fetchOne(
            'SELECT COUNT(*) FROM vehicles WHERE mot_history_enabled_at IS NOT NULL',
        );
        self::assertEquals(0, $enabled);
    }

    public function testDvsaFuelNames(): void
    {
        self::assertSame(FuelType::Petrol, VehicleLookup::fuel('PETROL'));
        self::assertSame(FuelType::Diesel, VehicleLookup::fuel('Diesel'));
        self::assertSame(FuelType::Electric, VehicleLookup::fuel('Electricity'));
        self::assertSame(FuelType::Hybrid, VehicleLookup::fuel('Hybrid Electric (Clean)'));
        self::assertSame(FuelType::Lpg, VehicleLookup::fuel('Gas Bi-Fuel'));
        self::assertNull(VehicleLookup::fuel('Steam'));
        self::assertNull(VehicleLookup::fuel(null));
    }

    private function value(HTMLDocument $document, string $name): string
    {
        $input = $document->querySelector('[name="' . $name . '"]');
        self::assertNotNull($input, $name);

        return (string) $input->getAttribute('value');
    }

    private function vehicles(): int
    {
        $count = $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM vehicles');

        return is_numeric($count) ? (int) $count : -1;
    }
}
