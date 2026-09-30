<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\UserRepository;
use Logbook\Repository\ValuationRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\View\View;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Valuations and depreciation (Phase 14.1, spec.md §7.1, §7.12, §7.13,
 * §7.16): the valuations page (page and modal), attachments, the overview's
 * *Ownership* card and value chart, History and *Recent activity*, never the
 * print view or a cost, and the CSV export.
 */
final class ValuationsTest extends AppTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    /** A valid 1×1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const array GOLF = [
        'type' => 'car',
        'make' => 'Volkswagen',
        'model' => 'Golf',
        'fuel_type' => 'petrol',
        'currency' => '',
        'purchase_date' => '2023-03-01',
        'purchase_price' => '15000',
    ];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        array_map(static fn (string $file) => is_file($file) && unlink($file), $this->tempFiles);
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function testAddEditAndDeleteAsAPageAndInTheModal(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $base = '/vehicles/' . $golf->id . '/valuations';

        $empty = self::body($browser->get($base));
        self::assertStringContainsString('No valuations yet', $empty);
        self::assertStringNotContainsString('valuations.csv', $empty, 'nothing to export yet');

        $form = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('name="valued_on"', $form);
        self::assertStringContainsString('value="2026-09-27"', $form, 'today by default');
        self::assertStringContainsString('name="attachments[]" type="file" multiple', $form);

        // Without JS: a page, then a redirect back to the list.
        $created = $browser->post($base . '/new', [
            'valued_on' => '2026-03-01',
            'amount' => '9800',
            'source' => 'Auto Trader valuation',
            'notes' => 'Online, 36k miles',
        ], ['attachments' => [$this->png('quote.png')]]);
        self::assertSame(303, $created->getStatusCode(), self::body($created));
        self::assertSame($base, $created->getHeaderLine('Location'));

        // From the modal: 204 with where to go.
        $modal = $browser->post(
            $base . '/new',
            ['valued_on' => '2025-03-01', 'amount' => '11200', 'source' => 'Part-exchange offer'],
            headers: [View::MODAL_HEADER => '1'],
        );
        self::assertSame(204, $modal->getStatusCode(), self::body($modal));

        $list = self::body($browser->get($base));
        self::assertLessThan(strpos($list, 'Part-exchange offer'), strpos($list, 'Auto Trader valuation'), 'newest first');
        self::assertStringContainsString('£9,800.00', $list);
        self::assertStringContainsString('valuations.csv', $list);
        self::assertStringContainsString('attach_file', self::rowOf($list, 'Auto Trader valuation'), 'its paperclip');

        $valuations = $this->valuations($app, $golf);
        self::assertCount(2, $valuations);
        $online = $valuations[1];
        self::assertSame('9800.000', $online->data->amount);
        self::assertSame('Online, 36k miles', $online->data->notes);

        // The file is served to the owner, through the authenticated handler.
        $quote = $this->onlyFile($app, $golf);
        self::assertSame(AttachmentOwner::Valuation, $quote->ownerType);
        self::assertSame($online->id, $quote->ownerId);
        $served = $browser->get('/vehicles/' . $golf->id . '/attachments/' . $quote->id);
        self::assertSame(200, $served->getStatusCode());
        self::assertSame('image/png', $served->getHeaderLine('Content-Type'));

        // Edit: the file is listed with its delete link; saving changes the value.
        $edit = $base . '/' . $online->id . '/edit';
        $editForm = self::body($browser->get($edit));
        self::assertStringContainsString('Delete quote.png', $editForm);
        self::assertStringContainsString('value="Auto Trader valuation"', $editForm);
        $updated = $browser->post($edit, ['valued_on' => '2026-03-01', 'amount' => '9750', 'source' => 'Auto Trader']);
        self::assertSame(303, $updated->getStatusCode(), self::body($updated));
        self::assertSame('9750.000', $this->valuations($app, $golf)[1]->data->amount);

        // Deleting one file returns to the valuation's edit form.
        $removed = $browser->post('/vehicles/' . $golf->id . '/attachments/' . $quote->id . '/delete');
        self::assertSame($edit, $removed->getHeaderLine('Location'));

        // Delete: a confirmation page (no JS needed), then it and its files are gone.
        $browser->post($edit, ['valued_on' => '2026-03-01', 'amount' => '9750'], ['attachments' => [$this->png('again.png')]]);
        $stored = $this->onlyFile($app, $golf)->storedPath;
        $confirm = self::body($browser->get($base . '/' . $online->id . '/delete'));
        self::assertStringContainsString('Delete this valuation?', $confirm);
        $deleted = $browser->post($base . '/' . $online->id . '/delete');
        self::assertSame($base, $deleted->getHeaderLine('Location'));
        self::assertCount(1, $this->valuations($app, $golf));
        self::assertSame([], $this->files($app, $golf));
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $stored);

        self::assertSame(404, $browser->get($base . '/999/edit')->getStatusCode());
    }

    public function testRefusalsKeepTheTypedValues(): void
    {
        [$app, $browser, $golf] = $this->golf(['sale_date' => '2026-06-12', 'sale_price' => '9000']);
        $new = '/vehicles/' . $golf->id . '/valuations/new';
        $browser->get($new);

        $future = $browser->post($new, ['valued_on' => '2026-09-28', 'amount' => '9800', 'source' => 'Tomorrow']);
        self::assertSame(422, $future->getStatusCode());
        self::assertStringContainsString('A valuation cannot be in the future.', self::body($future));
        self::assertStringContainsString('value="Tomorrow"', self::body($future));

        $early = $browser->post($new, ['valued_on' => '2023-02-28', 'amount' => '9800']);
        self::assertStringContainsString('A valuation cannot be before the purchase date.', self::body($early));

        $late = $browser->post($new, ['valued_on' => '2026-06-13', 'amount' => '9800']);
        self::assertStringContainsString(
            'This vehicle was sold on 12 Jun 2026; its sale price is its final value.',
            self::body($late),
        );

        $negative = $browser->post($new, ['valued_on' => '2026-01-01', 'amount' => '-1']);
        self::assertSame(422, $negative->getStatusCode());

        $zero = $browser->post($new, ['valued_on' => '2026-06-12', 'amount' => '0']);
        self::assertSame(303, $zero->getStatusCode(), 'the sale day, and a value of 0, are fine');
        self::assertCount(1, $this->valuations($app, $golf));
    }

    public function testAnArchivedVehicleCanStillTakeOne(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $browser->post('/vehicles/' . $golf->id . '/archive');
        self::assertTrue($this->vehicle($app, $golf->id)->isArchived());

        $created = $this->value($browser, $golf, ['valued_on' => '2026-09-01', 'amount' => '150', 'source' => 'Scrap value']);
        self::assertSame(303, $created->getStatusCode(), self::body($created));
    }

    public function testTheOwnershipCardShowsDepreciationAndTheValueOverTime(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $odometer = $this->service($app, OdometerService::class);
        $odometer->create($golf, new OdometerReadingData('10000', new DateTimeImmutable('2023-03-01T12:00:00Z')));
        $odometer->create($golf, new OdometerReadingData('67936.384', new DateTimeImmutable('2026-03-01T12:00:00Z')));

        $before = self::body($browser->get('/vehicles/' . $golf->id));
        $card = self::cardOf($before, 'ownership-heading');
        self::assertStringContainsString('Add a valuation to see what it has lost.', $card);
        self::assertStringNotContainsString('data-chart', $card, 'one point is not a chart');

        $this->value($browser, $golf, ['valued_on' => '2026-03-01', 'amount' => '9800', 'source' => 'Auto Trader valuation']);
        $html = self::body($browser->get('/vehicles/' . $golf->id));
        $card = self::cardOf($html, 'ownership-heading');

        self::assertStringContainsString('1 Mar 2023 · <span class="tabular">£15,000.00</span>', $card, 'bought');
        self::assertStringContainsString('Latest value', $card);
        self::assertStringContainsString('Auto Trader valuation', $card);
        self::assertStringContainsString('Down £5,200.00 (-35%)', $card);
        self::assertStringContainsString('£1,733.33', $card, 'per year, to the value’s date');
        self::assertStringContainsString('£0.144/mi', $card, 'per mile driven');
        self::assertStringNotContainsString('add a new valuation', $card, 'stale only after 12 months');
        self::assertStringContainsString('data-chart', $card);
        self::assertStringContainsString('&quot;timeZone&quot;:&quot;UTC&quot;', $card, 'calendar dates stay on their day');
        self::assertStringContainsString('<table class="table chart-table">', $card, 'the same points without JS');
        self::assertStringContainsString('/valuations', $card);

        // Currency and Added now live on the Details card.
        $details = self::cardOf($html, 'details-heading');
        self::assertStringContainsString('GBP — ', $details);
        self::assertStringContainsString('Added', $details);
    }

    public function testTheCardIsHiddenWithoutPurchaseSaleOrValuation(): void
    {
        [, $browser, $golf] = $this->golf(['purchase_date' => '', 'purchase_price' => '']);

        $html = self::body($browser->get('/vehicles/' . $golf->id));
        self::assertStringNotContainsString('ownership-heading', $html);
        self::assertStringContainsString('GBP — ', self::cardOf($html, 'details-heading'), 'the currency is still shown');

        $edit = self::body($browser->get('/vehicles/' . $golf->id . '/edit'));
        self::assertStringContainsString('/vehicles/' . $golf->id . '/valuations', $edit, 'the form links to the valuations');
    }

    public function testASoldVehicleUsesItsSalePriceAndIsNeverStale(): void
    {
        [, $browser, $golf] = $this->golf(['sale_date' => '2024-06-01', 'sale_price' => '12000']);
        $browser->post('/vehicles/' . $golf->id . '/valuations/new', ['valued_on' => '2024-01-01', 'amount' => '13000']);

        $card = self::cardOf(self::body($browser->get('/vehicles/' . $golf->id)), 'ownership-heading');
        self::assertStringContainsString('Down £3,000.00 (-20%)', $card);
        self::assertStringNotContainsString('Latest value', $card, 'the sale is the final value');
        self::assertStringNotContainsString('add a new valuation', $card);
    }

    public function testValuationsAreInHistoryAndRecentActivityButNeverPrintedOrCosted(): void
    {
        [, $browser, $golf] = $this->golf();
        $this->value($browser, $golf, ['valued_on' => '2026-03-01', 'amount' => '9800', 'source' => 'Auto Trader valuation']);

        $history = self::body($browser->get('/vehicles/' . $golf->id . '/history'));
        $row = self::rowOf($history, 'Valued at £9,800.00');
        self::assertStringContainsString('Auto Trader valuation', $row);
        self::assertStringNotContainsString('list__amount', $row, 'a value, not a cost');
        self::assertStringContainsString('/valuations/', $row, 'links to its edit form');

        $expenses = self::body($browser->get('/vehicles/' . $golf->id . '/history?kind=expenses'));
        self::assertStringNotContainsString('Valued at', $expenses, 'under Everything only');

        self::assertStringContainsString('Valued at £9,800.00', self::body($browser->get('/')), 'Recent activity');
        $overview = self::body($browser->get('/vehicles/' . $golf->id));
        self::assertStringContainsString('Valued at £9,800.00', $overview, 'Recent history');

        $everything = '?options=1&costs=1&kinds[]=service&kinds[]=fuel&kinds[]=tyres'
            . '&kinds[]=documents&kinds[]=expenses&kinds[]=mileage';
        foreach (['', $everything] as $query) {
            $print = self::body($browser->get('/vehicles/' . $golf->id . '/history/print' . $query));
            self::assertStringNotContainsString('Valued at', $print, 'never printed: ' . $query);
            self::assertStringNotContainsString('Auto Trader valuation', $print);
        }

        $spend = self::body($browser->get('/vehicles/' . $golf->id . '/expenses'));
        self::assertStringNotContainsString('9,800', $spend, 'not a cost');
        self::assertStringNotContainsString('9,800', self::body($browser->get('/reports')));
    }

    public function testTheCsvExport(): void
    {
        [, $browser, $golf] = $this->golf();
        $base = '/vehicles/' . $golf->id . '/valuations/new';
        $browser->post($base, [
            'valued_on' => '2026-03-01',
            'amount' => '9800.5',
            'source' => '=cmd|x',
            'notes' => 'Line one, "quoted"',
        ]);
        $browser->post($base, ['valued_on' => '2025-03-01', 'amount' => '0']);

        $response = $browser->get('/vehicles/' . $golf->id . '/export/valuations.csv');
        self::assertSame(200, $response->getStatusCode());
        $disposition = $response->getHeaderLine('Content-Disposition');
        self::assertStringContainsString('logbook-volkswagen-golf-valuations-2026-09-27.csv', $disposition);
        $lines = explode("\r\n", trim(substr(self::body($response), 3)));
        self::assertSame('Date,Amount,Currency,Source,Notes', $lines[0]);
        self::assertSame('2025-03-01,0.00,GBP,,', $lines[1], 'oldest first');
        self::assertSame("2026-03-01,9800.50,GBP,'=cmd|x,\"Line one, \"\"quoted\"\"\"", $lines[2], 'quoted and defused');

        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/import/valuations')->getStatusCode(), 'no import');
    }

    public function testDeletingTheVehicleRemovesItsValuationsAndTheirFiles(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $this->value($browser, $golf, ['valued_on' => '2026-03-01', 'amount' => '9800'], [$this->png('quote.png')]);
        $stored = $this->onlyFile($app, $golf)->storedPath;

        $browser->get('/vehicles/' . $golf->id . '/delete');
        $deleted = $browser->post('/vehicles/' . $golf->id . '/delete');
        self::assertSame(303, $deleted->getStatusCode());

        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM vehicle_valuations'));
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM attachments'));
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $stored);
    }

    public function testAValuationAndItsFilesAreServedOnlyThroughTheirOwnVehicleAndWhenSignedIn(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $this->value($browser, $golf, ['valued_on' => '2026-03-01', 'amount' => '9800'], [$this->png('quote.png')]);
        $valuation = $this->valuations($app, $golf)[0];
        $file = $this->onlyFile($app, $golf);
        $browser->post('/vehicles/new', ['type' => 'bike', 'make' => 'Honda', 'model' => 'CB500', 'fuel_type' => 'petrol']);
        $bike = $this->connection($app)->fetchOne('SELECT id FROM vehicles WHERE make = ?', ['Honda']);
        self::assertIsScalar($bike);

        self::assertSame(404, $browser->get('/vehicles/' . $bike . '/valuations/' . $valuation->id . '/edit')->getStatusCode());
        self::assertSame(404, $browser->get('/vehicles/' . $bike . '/attachments/' . $file->id)->getStatusCode());

        $browser->forgetCookies();
        $signedOut = $browser->get('/vehicles/' . $golf->id . '/attachments/' . $file->id);
        self::assertSame(303, $signedOut->getStatusCode());
        self::assertStringContainsString('/login', $signedOut->getHeaderLine('Location'));
    }

    /**
     * @param array<string, string> $overrides
     * @return array{App<ContainerInterface>, TestBrowser, Vehicle}
     */
    private function golf(array $overrides = []): array
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');
        $created = $browser->post('/vehicles/new', $overrides + self::GOLF);
        self::assertSame(303, $created->getStatusCode(), self::body($created));

        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);
        $vehicles = $this->ownedVehicles($app, $owner->id);
        self::assertCount(1, $vehicles);

        return [$app, $browser, $vehicles[0]];
    }

    /**
     * POST the add-valuation form.
     *
     * @param array<string, string> $fields
     * @param list<UploadedFile> $files
     */
    private function value(TestBrowser $browser, Vehicle $vehicle, array $fields, array $files = []): ResponseInterface
    {
        $path = '/vehicles/' . $vehicle->id . '/valuations/new';

        return $browser->post($path, $fields, $files === [] ? [] : ['attachments' => $files]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function vehicle(App $app, int $id): Vehicle
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);
        $vehicle = $this->service($app, VehicleRepository::class)->findById($id);
        self::assertNotNull($vehicle);

        return $vehicle;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<VehicleValuation>
     */
    private function valuations(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, ValuationRepository::class)->listForVehicle($vehicle->id);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<Attachment>
     */
    private function files(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, AttachmentRepository::class)->listForVehicle($vehicle->id);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function onlyFile(App $app, Vehicle $vehicle): Attachment
    {
        $files = $this->files($app, $vehicle);
        self::assertCount(1, $files);

        return $files[0];
    }

    /**
     * The list item (or link row) that contains $needle.
     */
    private static function rowOf(string $html, string $needle): string
    {
        $at = strpos($html, $needle);
        self::assertNotFalse($at, $needle);
        $start = max((int) strrpos(substr($html, 0, $at), '<li'), (int) strrpos(substr($html, 0, $at), '<a class="list__item'));
        $end = strpos($html, '</li>', $at);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    /**
     * The card section labelled by $headingId.
     */
    private static function cardOf(string $html, string $headingId): string
    {
        $at = strpos($html, 'aria-labelledby="' . $headingId . '"');
        self::assertNotFalse($at, $headingId);
        $end = strpos($html, '</section>', $at);

        return substr($html, $at, ($end === false ? strlen($html) : $end) - $at);
    }

    private function png(string $name): UploadedFile
    {
        $contents = (string) base64_decode(self::PNG);
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-upload-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, 'image/png', strlen($contents), UPLOAD_ERR_OK);
    }
}
