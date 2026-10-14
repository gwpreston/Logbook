<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use Dom\HTMLDocument;
use Logbook\Tests\Support\Html;

/**
 * *Look up* in *Set up your first vehicle* (Phase 49.1, spec.md §7.9, §7.38):
 * the blank fields are filled, the MOT expiry is carried to the key dates
 * step, and nothing is stored by the look-up itself.
 */
final class GettingStartedLookupTest extends MotHistoryTestCase
{
    public function testLookUpFillsTheStepAndTheMotDateIsCarriedToKeyDates(): void
    {
        $this->start();
        $browser = $this->browserFor($this->app, 'owner');
        self::assertStringContainsString('name="lookup"', (string) $browser->get('/vehicles/start')->getBody());

        $response = $browser->post('/vehicles/start', [
            'step' => 'vehicle', 'type' => 'car', 'make' => '', 'model' => '', 'fuel_type' => 'petrol',
            'registration' => 'ab12 cde', 'lookup' => '1',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $document = Html::document((string) $response->getBody());
        self::assertSame('Volkswagen', $this->value($document, 'make'));
        self::assertSame(0, $this->vehicles(), 'nothing is stored by a look-up');
        $expiry = $this->value($document, 'mot_expires_on');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $expiry);

        $browser->post('/vehicles/start', [
            'step' => 'vehicle', 'type' => 'car', 'make' => 'Volkswagen', 'model' => 'Golf', 'fuel_type' => 'petrol',
            'registration' => 'AB12 CDE', 'mot_expires_on' => $expiry, 'first_inspection_due_on' => '',
        ]);
        $browser->post('/vehicles/start', ['step' => 'mileage', 'skip' => '1']);

        $dates = Html::document((string) $browser->get('/vehicles/start')->getBody());
        self::assertSame($expiry, $this->value($dates, 'mot_due_on'), 'the MOT date is already filled in');
        self::assertSame(1, $this->vehicles());
    }

    public function testNoLookUpButtonWhileTheProviderIsOff(): void
    {
        $this->start(enable: false);
        $browser = $this->browserFor($this->app, 'owner');

        self::assertStringNotContainsString('name="lookup"', (string) $browser->get('/vehicles/start')->getBody());
        $response = $browser->post('/vehicles/start', ['step' => 'vehicle', 'registration' => 'AB12 CDE', 'lookup' => '1']);

        self::assertStringContainsString('MOT history is off.', (string) $response->getBody());
        self::assertSame(0, $this->vehicles());
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
