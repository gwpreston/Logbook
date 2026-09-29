<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Support\View\View;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Purchase and sale paperwork (Phase 12, spec.md §7.1, §7.12, §7.16): two
 * inputs on the vehicle form, sharing one limit per save, all or nothing;
 * the files need their dates, and show on the *Bought* and *Sold*
 * milestones, the overview's *Ownership* card and in print.
 */
final class VehiclePaperworkTest extends AppTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    private const string PDF = "%PDF-1.4\n"
        . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        . "2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n"
        . "trailer<</Root 1 0 R>>\n%%EOF\n";

    private const array GOLF = [
        'type' => 'car',
        'make' => 'Volkswagen',
        'model' => 'Golf',
        'fuel_type' => 'petrol',
        'currency' => '',
        'purchase_date' => '2021-05-01',
        'purchase_price' => '12500',
        'sale_date' => '2026-09-01',
        'sale_price' => '8000',
    ];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        array_map(static fn (string $file) => is_file($file) && unlink($file), $this->tempFiles);
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function testAddingAndEditingTakesPurchaseAndSaleFilesAsAPageAndInTheModal(): void
    {
        [$app, $browser] = $this->app();

        $form = self::body($browser->get('/vehicles/new'));
        self::assertStringContainsString('name="purchase_attachments[]" type="file" multiple', $form);
        self::assertStringContainsString('name="sale_attachments[]" type="file" multiple', $form);
        self::assertSame(2, substr_count($form, 'data-max-files-group="paperwork"'), 'one shared limit');
        self::assertStringContainsString('Up to 10 files per save for purchase and sale together', $form);
        self::assertStringContainsString('Purchase paperwork', $form);
        self::assertStringContainsString('Sale paperwork', $form);
        self::assertStringNotContainsString('id="f-attachments"', $form, 'no second generic input');

        $created = $browser->post('/vehicles/new', self::GOLF, [
            'purchase_attachments' => [$this->pdf('invoice.pdf'), $this->pdf('v5c-slip.pdf')],
            'sale_attachments' => [$this->pdf('receipt.pdf')],
        ]);
        self::assertSame(303, $created->getStatusCode(), self::body($created));
        $golf = $this->onlyVehicle($app);
        self::assertSame(
            ['purchase' => ['invoice.pdf', 'v5c-slip.pdf'], 'sale' => ['receipt.pdf']],
            $this->byOwner($app, $golf),
        );
        foreach ($this->attachments($app, $golf) as $attachment) {
            self::assertSame($golf->id, $attachment->ownerId, 'the owner is the vehicle');
        }

        // Edit, as a page and from the modal: more files, the ones there listed with delete links.
        $edit = '/vehicles/' . $golf->id . '/edit';
        $editForm = self::body($browser->get($edit));
        self::assertStringContainsString('invoice.pdf', $editForm);
        self::assertStringContainsString('Delete receipt.pdf', $editForm);
        $page = $browser->post($edit, self::GOLF, ['sale_attachments' => [$this->pdf('handover.pdf')]]);
        self::assertSame(303, $page->getStatusCode(), self::body($page));
        $warranty = ['purchase_attachments' => [$this->pdf('warranty.pdf')]];
        $modal = $browser->post($edit, self::GOLF, $warranty, headers: [View::MODAL_HEADER => '1']);
        self::assertSame(204, $modal->getStatusCode(), self::body($modal));
        self::assertSame(
            ['purchase' => ['invoice.pdf', 'v5c-slip.pdf', 'warranty.pdf'], 'sale' => ['receipt.pdf', 'handover.pdf']],
            $this->byOwner($app, $golf),
        );

        // A plain save (no files) keeps them.
        $browser->post($edit, ['sale_price' => '8100'] + self::GOLF);
        self::assertCount(5, $this->attachments($app, $golf));

        // Deleting one returns to the vehicle's edit form.
        $receipt = $this->named($app, $golf, 'receipt.pdf');
        $deleted = $browser->post('/vehicles/' . $golf->id . '/attachments/' . $receipt->id . '/delete');
        self::assertSame($edit, $deleted->getHeaderLine('Location'));
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $receipt->storedPath);
    }

    public function testBothInputsShareOneLimitAndOneBadFileWritesNothing(): void
    {
        [$app, $browser] = $this->app();
        $browser->get('/vehicles/new');

        $refused = $browser->post('/vehicles/new', self::GOLF, [
            'purchase_attachments' => $this->pdfs(6),
            'sale_attachments' => $this->pdfs(5),
        ]);
        self::assertSame(422, $refused->getStatusCode());
        $html = self::body($refused);
        self::assertStringContainsString('Up to 10 files', $html);
        self::assertStringContainsString('value="Volkswagen"', $html, 'typed values are kept');
        self::assertSame([], $this->vehicles($app), 'nothing written');
        self::assertSame([], $this->storedFiles());

        $bad = $browser->post('/vehicles/new', self::GOLF, [
            'purchase_attachments' => [$this->pdf('invoice.pdf')],
            'sale_attachments' => [$this->upload('not a pdf', 'receipt.heic', 'image/heic')],
        ]);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('receipt.heic', self::body($bad));
        self::assertSame([], $this->vehicles($app));
        self::assertSame([], $this->storedFiles(), 'the good file was not stored either');

        // Ten across both is fine.
        $ten = $browser->post('/vehicles/new', self::GOLF, [
            'purchase_attachments' => $this->pdfs(6),
            'sale_attachments' => $this->pdfs(4),
        ]);
        self::assertSame(303, $ten->getStatusCode(), self::body($ten));
        self::assertCount(10, $this->attachments($app, $this->onlyVehicle($app)));
    }

    public function testPaperworkNeedsItsDate(): void
    {
        [$app, $browser] = $this->app();
        $browser->get('/vehicles/new');

        $undated = $browser->post('/vehicles/new', ['sale_date' => ''] + self::GOLF, [
            'sale_attachments' => [$this->pdf('receipt.pdf')],
        ]);
        self::assertSame(422, $undated->getStatusCode());
        self::assertStringContainsString('Add the sale date to attach the sale paperwork.', self::body($undated));
        self::assertSame([], $this->vehicles($app));
        self::assertSame([], $this->storedFiles(), 'refused before any file is written');

        $browser->post('/vehicles/new', self::GOLF, ['purchase_attachments' => [$this->pdf('invoice.pdf')]]);
        $golf = $this->onlyVehicle($app);
        $edit = '/vehicles/' . $golf->id . '/edit';

        $cleared = $browser->post($edit, ['purchase_date' => ''] + self::GOLF);
        self::assertSame(422, $cleared->getStatusCode());
        $html = self::body($cleared);
        self::assertStringContainsString('Remove the purchase paperwork first, or keep the purchase date.', $html);
        self::assertStringContainsString('id="f-purchase_date-error"', $html, 'shown on the date');
        self::assertSame('2021-05-01', $this->onlyVehicle($app)->data->purchaseDate?->format('Y-m-d'), 'nothing saved');

        // Without files, clearing a date is fine.
        $noSale = $browser->post($edit, ['sale_date' => '', 'sale_price' => ''] + self::GOLF);
        self::assertSame(303, $noSale->getStatusCode(), self::body($noSale));
        self::assertNull($this->onlyVehicle($app)->data->saleDate);

        $noDate = $browser->post($edit, ['sale_date' => ''] + self::GOLF, ['sale_attachments' => [$this->pdf('r.pdf')]]);
        self::assertSame(422, $noDate->getStatusCode());
        self::assertStringContainsString('id="f-sale_attachments-error"', self::body($noDate), 'shown on the input');
        self::assertCount(1, $this->attachments($app, $golf));
    }

    public function testFilesAreTheOwnersOnlyGoWithTheVehicleAndStayWhenArchived(): void
    {
        [$app, $browser] = $this->app();
        $browser->get('/vehicles/new');
        $browser->post('/vehicles/new', self::GOLF, ['sale_attachments' => [$this->pdf('receipt.pdf')]]);
        $golf = $this->onlyVehicle($app);
        $receipt = $this->named($app, $golf, 'receipt.pdf');
        $url = '/vehicles/' . $golf->id . '/attachments/' . $receipt->id;

        self::assertSame(200, $browser->get($url)->getStatusCode());
        self::assertSame(self::PDF, self::body($browser->get($url)));
        $anonymous = (new TestBrowser($app))->get($url);
        self::assertSame(303, $anonymous->getStatusCode());
        self::assertStringStartsWith('/login', $anonymous->getHeaderLine('Location'));

        $browser->post('/vehicles/' . $golf->id . '/archive');
        self::assertCount(1, $this->attachments($app, $golf), 'archiving keeps them');
        self::assertFileExists($this->uploadDir() . '/' . $receipt->storedPath);
        self::assertStringContainsString('title="1 file"', self::body($browser->get('/vehicles/' . $golf->id . '/history')));

        $confirm = self::body($browser->get('/vehicles/' . $golf->id . '/delete'));
        self::assertStringContainsString('purchase and sale paperwork', $confirm);
        $browser->post('/vehicles/' . $golf->id . '/delete');
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $receipt->storedPath, 'deleting the vehicle deletes them');
        self::assertSame([], $this->storedFiles());
    }

    public function testPaperclipsOnTheMilestonesTheOverviewAndFileNamesInPrint(): void
    {
        [$app, $browser] = $this->app();
        $browser->get('/vehicles/new');
        $browser->post('/vehicles/new', ['first_registered_on' => '2019-03-14'] + self::GOLF, [
            'purchase_attachments' => [$this->pdf('invoice.pdf'), $this->pdf('v5c-slip.pdf')],
            'sale_attachments' => [$this->pdf('receipt.pdf')],
        ]);
        $golf = $this->onlyVehicle($app);
        $base = '/vehicles/' . $golf->id;

        foreach (['2021' => ['Bought', '2 files'], '2026' => ['Sold', '1 file']] as $year => [$milestone, $files]) {
            $history = self::body($browser->get($base . '/history?year=' . $year));
            $row = self::rowOf($history, '>' . $milestone . ' for ');
            self::assertStringContainsString('title="' . $files . '"', $row, $milestone);
        }
        $registered = self::rowOf(self::body($browser->get($base . '/history?year=2019')), '>First registered<');
        self::assertStringNotContainsString('attachment-count', $registered);

        // The fleet history lists the active vehicles' milestones.
        $fleet = self::body($browser->get('/history?year=2021'));
        self::assertStringContainsString('title="2 files"', self::rowOf($fleet, '>Bought for '));

        $overview = self::body($browser->get($base));
        self::assertStringContainsString('title="Purchase paperwork: 2 files"', $overview);
        self::assertStringContainsString('title="Sale paperwork: 1 file"', $overview);
        self::assertStringContainsString('class="ownership-files" href="' . $base . '/edit" data-modal', $overview);

        $print = self::body($browser->get($base . '/history/print'));
        self::assertStringContainsString('invoice.pdf, v5c-slip.pdf', $print, 'file names under Bought');
        self::assertStringContainsString('receipt.pdf', $print);
        self::assertStringNotContainsString('£12,500', $print, 'prices hidden by default');
    }

    public function testAVehicleWithoutPaperworkShowsNoPaperclip(): void
    {
        [$app, $browser] = $this->app();
        $browser->get('/vehicles/new');
        $browser->post('/vehicles/new', self::GOLF);
        $golf = $this->onlyVehicle($app);

        self::assertStringNotContainsString('ownership-files', self::body($browser->get('/vehicles/' . $golf->id)));
        $history = self::body($browser->get('/vehicles/' . $golf->id . '/history?year=2026'));
        self::assertStringNotContainsString('attachment-count', $history);
    }

    /**
     * @return array{App<ContainerInterface>, TestBrowser}
     */
    private function app(): array
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);

        return [$app, $this->signedIn($app)];
    }

    /**
     * The list item that contains $needle.
     */
    private static function rowOf(string $html, string $needle): string
    {
        $at = strpos($html, $needle);
        self::assertNotFalse($at, $needle);
        $start = (int) strrpos(substr($html, 0, $at), '<li');
        $end = strpos($html, '</li>', $at);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    private function pdf(string $name): UploadedFile
    {
        return $this->upload(self::PDF, $name, 'application/pdf');
    }

    /**
     * @return list<UploadedFile>
     */
    private function pdfs(int $count): array
    {
        return array_map(fn (int $i): UploadedFile => $this->pdf('file-' . $i . '.pdf'), range(1, $count));
    }

    private function upload(string $contents, string $name, string $type): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-upload-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, $type, strlen($contents), UPLOAD_ERR_OK);
    }

    /**
     * @return list<string> files stored under UPLOAD_PATH/attachments
     */
    private function storedFiles(): array
    {
        return array_map(basename(...), glob($this->uploadDir() . '/attachments/*') ?: []);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<Vehicle>
     */
    private function vehicles(App $app): array
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $this->service($app, VehicleRepository::class)->listForUser($owner->id, true);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function onlyVehicle(App $app): Vehicle
    {
        $vehicles = $this->vehicles($app);
        self::assertCount(1, $vehicles);

        return $vehicles[0];
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<Attachment>
     */
    private function attachments(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, AttachmentRepository::class)->listForVehicle($vehicle->id);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, list<string>> owner type → file names
     */
    private function byOwner(App $app, Vehicle $vehicle): array
    {
        $names = [];
        foreach ($this->attachments($app, $vehicle) as $attachment) {
            self::assertContains($attachment->ownerType, [AttachmentOwner::Purchase, AttachmentOwner::Sale]);
            $names[$attachment->ownerType->value][] = $attachment->filename;
        }

        return $names;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function named(App $app, Vehicle $vehicle, string $name): Attachment
    {
        foreach ($this->attachments($app, $vehicle) as $attachment) {
            if ($attachment->filename === $name) {
                return $attachment;
            }
        }

        self::fail('No attachment ' . $name);
    }
}
