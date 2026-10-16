<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use DateTimeZone;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\ModificationRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\PartRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Repository\AttachmentRepository;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartRemoval;
use Logbook\Service\Part\PartService;
use Logbook\Service\Part\RecordParts;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * The parts and modifications pages (spec.md §7.39): logging a modification
 * with its parts in one save, the service record's parts lists, fitting and
 * removing on a part's page, *Revert*, deleting, *Make this a modification*,
 * build spend kept out of every cost per distance, access, archived
 * vehicles, a subpath and the module switched off.
 */
final class PartsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    private const string NOW = '2026-10-14T10:00:00Z';

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function form(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Cat-back exhaust',
            'category' => 'exhaust',
            'done_on' => '2026-05-10',
            'odometer' => '37000',
            'cost' => '899',
            'fitted_by' => 'Self',
            'parts_lists' => '1',
            'new_rows' => '1',
            'off_rows' => '1',
            'new_0_name' => 'Cat-back system',
            'new_0_brand' => 'Milltek',
            'new_0_part_number' => 'SSXVW123',
            'new_0_price' => '780',
            'new_0_warranty_months' => '24',
            'off_0_name' => 'Standard exhaust',
            'off_0_outcome' => 'kept',
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function modification(App $app, Vehicle $vehicle): Modification
    {
        $list = $this->service($app, ModificationRepository::class)->listForVehicles([$vehicle->id]);
        self::assertCount(1, $list);

        return $list[0];
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, Part>
     */
    private function parts(App $app, Vehicle $vehicle): array
    {
        $parts = [];
        foreach ($this->service($app, PartRepository::class)->listForVehicles([$vehicle->id]) as $part) {
            $parts[$part->data->name] = $part;
        }

        return $parts;
    }

    public function testAModificationWithItsPartsIsOneSave(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = (string) $golf->id;
        $v = '/vehicles/' . $id;

        self::assertStringContainsString('/log/new/modification', self::body($browser->get('/log/new')));
        self::assertStringContainsString($v . '/parts"', self::body($browser->get($v . '/maintenance')));
        $form = self::body($browser->get($v . '/modifications/new'));
        self::assertStringContainsString('name="new_0_name"', $form);
        self::assertStringContainsString('name="off_0_name"', $form);

        $response = $browser->post($v . '/modifications/new', self::form());
        self::assertSame(303, $response->getStatusCode());
        $modification = $this->modification($app, $golf);
        self::assertStringEndsWith($v . '/modifications/' . $modification->id, $response->getHeaderLine('Location'));
        self::assertSame('899.000', $modification->data->cost);
        $parts = $this->parts($app, $golf);
        self::assertSame(PartStatus::Fitted, $parts['Cat-back system']->status);
        self::assertSame('780.000', $parts['Cat-back system']->data->price);
        self::assertSame(24, $parts['Cat-back system']->data->warrantyMonths);
        self::assertSame(PartStatus::Stored, $parts['Standard exhaust']->status);
        $reading = $this->service($app, OdometerReadingRepository::class)
            ->findByEntry($golf->id, OdometerSource::Modification, $modification->id);
        self::assertNotNull($reading, 'the mileage joins the log');

        $page = self::body($browser->get($v . '/modifications/' . $modification->id));
        self::assertStringContainsString('Cat-back exhaust', $page);
        self::assertStringContainsString('Milltek Cat-back system', $page);
        self::assertStringContainsString('Standard exhaust', $page);
        self::assertStringContainsString('not in cost per mile', $page);

        $tab = self::body($browser->get($v . '/parts'));
        self::assertStringContainsString('Cat-back exhaust', $tab);
        self::assertStringContainsString('Milltek Cat-back system', $tab);
        self::assertStringNotContainsString('Standard exhaust', $tab, 'Fitted is the default filter');
        self::assertStringContainsString('Standard exhaust', self::body($browser->get($v . '/parts?status=stored')));

        $part = self::body($browser->get($v . '/parts/' . $parts['Cat-back system']->id));
        self::assertStringContainsString('In warranty until 10 May 2028', $part);
        self::assertStringContainsString('SSXVW123', $part);

        $overview = self::body($browser->get('/vehicles/' . $id));
        self::assertStringContainsString('Build spend', $overview);
        self::assertStringContainsString('Modification', self::body($browser->get($v . '/odometer')));
        $history = self::body($browser->get($v . '/history?kind=parts'));
        self::assertStringContainsString('Cat-back exhaust', $history);
        self::assertStringContainsString('Fitted Milltek Cat-back system', $history);
    }

    public function testTheExpensesTabAndTheMileageTabOpenTheModificationAndThePart(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = (string) $golf->id;
        $v = '/vehicles/' . $id;
        $browser->post($v . '/modifications/new', self::form(['off_0_outcome' => 'sold', 'off_0_sold_price' => '150']));
        $modification = $this->modification($app, $golf);
        $sold = $this->parts($app, $golf)['Standard exhaust'];

        $expenses = self::body($browser->get($v . '/expenses?range=all'));
        self::assertStringContainsString('href="' . $v . '/modifications/' . $modification->id . '"', $expenses);
        self::assertStringContainsString('href="' . $v . '/parts/' . $sold->id . '"', $expenses);
        self::assertMatchesRegularExpression('/(−|-)£150\.00/u', $expenses, 'money back');

        $readings = $this->service($app, OdometerReadingRepository::class);
        $reading = $readings->findByEntry($golf->id, OdometerSource::Modification, $modification->id);
        self::assertNotNull($reading);
        $edit = $browser->get($v . '/odometer/' . $reading->id . '/edit');
        self::assertStringEndsWith($v . '/modifications/' . $modification->id . '/edit', $edit->getHeaderLine('Location'));

        $bars = $browser->post(
            $v . '/parts/new',
            ['name' => 'Roof bars', 'on_car' => '1', 'fit_on' => '2026-06-01', 'fit_odometer' => '38000'],
        );
        self::assertSame(303, $bars->getStatusCode());
        $part = $this->parts($app, $golf)['Roof bars'];
        $fitting = $this->service($app, PartRepository::class)->fittingsOf($part->id)[0];
        $own = $readings->findByEntry($golf->id, OdometerSource::PartFitted, $fitting->id);
        self::assertNotNull($own);
        $edit = $browser->get($v . '/odometer/' . $own->id . '/edit');
        self::assertStringEndsWith($v . '/parts/' . $part->id, $edit->getHeaderLine('Location'));
    }

    public function testEditingAModificationAPartAndAServiceRecordWithItsParts(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $v = '/vehicles/' . $golf->id;
        $browser->post($v . '/modifications/new', self::form());
        $modification = $this->modification($app, $golf);
        $parts = $this->parts($app, $golf);
        $catBack = $parts['Cat-back system'];
        $standard = $parts['Standard exhaust'];
        $edit = $v . '/modifications/' . $modification->id . '/edit';

        // The edit form shows what it fits and takes off, ticked.
        $form = self::body($browser->get($edit));
        self::assertStringContainsString('name="fit_' . $catBack->id . '" value="1" checked', $form);
        self::assertStringContainsString('name="remove_' . $standard->id . '" value="1" checked', $form);
        self::assertSame(200, $browser->post($edit, self::form(['parts_more' => 'off']))->getStatusCode());
        self::assertSame(422, $browser->post($edit, self::form(['done_on' => '2026-10-30']))->getStatusCode());

        // Moved a day, the standard exhaust unticked: back on, the cat-back's fitting moved.
        $saved = $browser->post($edit, [
            'title' => 'Cat-back exhaust', 'category' => 'exhaust', 'done_on' => '2026-05-11', 'odometer' => '37010',
            'cost' => '899', 'parts_lists' => '1', 'new_rows' => '1', 'off_rows' => '1',
            'fit_' . $catBack->id => '1',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        $parts = $this->parts($app, $golf);
        self::assertSame(PartStatus::Fitted, $parts['Standard exhaust']->status, 'the removal was taken back');
        $fitting = $this->service($app, PartRepository::class)->fittingsOf($catBack->id)[0];
        self::assertSame('2026-05-11', $fitting->fittedOn->format('Y-m-d'));

        // Taking the standard exhaust off again on the modification's own date is allowed (spells may meet).
        $refused = $browser->post($edit, [
            'title' => 'Cat-back exhaust', 'category' => 'exhaust', 'done_on' => '2026-05-11', 'cost' => '899',
            'parts_lists' => '1', 'new_rows' => '1', 'off_rows' => '1', 'fit_' . $catBack->id => '1',
            'remove_' . $standard->id => '1', 'remove_' . $standard->id . '_outcome' => 'kept',
        ]);
        self::assertSame(303, $refused->getStatusCode(), 'taking it off again on the same date is fine');

        // A part's own edit keeps its history.
        $partEdit = $v . '/parts/' . $catBack->id . '/edit';
        self::assertStringContainsString('value="Cat-back system"', self::body($browser->get($partEdit)));
        self::assertSame(422, $browser->post($partEdit, ['name' => ''])->getStatusCode());
        $renamed = $browser->post(
            $partEdit,
            ['name' => 'Cat-back', 'brand' => 'Milltek', 'condition' => 'new', 'warranty_distance' => '20000'],
        );
        self::assertSame(303, $renamed->getStatusCode());
        $part = $this->parts($app, $golf)['Cat-back'];
        self::assertSame(PartStatus::Fitted, $part->status);
        self::assertNotNull($part->data->warrantyKm);
        self::assertStringContainsString('In warranty', self::body($browser->get($v . '/parts/' . $part->id)));

        // A service record: its form keeps the posted rows, and fits what it lists.
        $record = $this->maintenance($app, $golf, '2026-07-01', 'Clutch', '780.000', '39000.000');
        $recordEdit = $v . '/maintenance/' . $record->id . '/edit';
        self::assertSame(200, $browser->post($recordEdit, [
            'title' => 'Clutch', 'category' => 'repair', 'performed_on' => '2026-07-01', 'parts_lists' => '1',
            'new_rows' => '1', 'off_rows' => '1', 'parts_more' => 'new',
        ])->getStatusCode());
        $bad = $browser->post($recordEdit, [
            'title' => 'Clutch', 'category' => 'repair', 'performed_on' => '2026-07-01', 'parts_lists' => '1',
            'new_rows' => '1', 'off_rows' => '1', 'new_0_name' => 'Clutch kit', 'new_0_price' => 'lots',
        ]);
        self::assertSame(422, $bad->getStatusCode());
        $clash = $browser->post($recordEdit, [
            'title' => 'Clutch', 'category' => 'repair', 'performed_on' => '2026-04-01', 'parts_lists' => '1',
            'new_rows' => '1', 'off_rows' => '1', 'remove_' . $part->id => '1', 'remove_' . $part->id . '_outcome' => 'kept',
        ]);
        self::assertSame(422, $clash->getStatusCode(), 'off before it went on');
        self::assertStringContainsString('would come off', self::body($clash));
        $ok = $browser->post($recordEdit, [
            'title' => 'Clutch', 'category' => 'repair', 'performed_on' => '2026-07-01', 'cost' => '780', 'parts_lists' => '1',
            'new_rows' => '1', 'off_rows' => '1', 'new_0_name' => 'Clutch kit',
        ]);
        self::assertSame(303, $ok->getStatusCode());
        self::assertSame(PartStatus::Fitted, $this->parts($app, $golf)['Clutch kit']->status);
    }

    public function testHistoryListsAPartFittedAndRemovedWithoutARecordAndARevert(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $v = '/vehicles/' . $golf->id;
        $browser->post(
            $v . '/parts/new',
            ['name' => 'Roof bars', 'on_car' => '1', 'fit_on' => '2026-01-10', 'fit_odometer' => '30000'],
        );
        $bars = $this->parts($app, $golf)['Roof bars'];
        $browser->post(
            $v . '/parts/' . $bars->id . '/remove',
            ['removed_on' => '2026-03-01', 'odometer' => '30000', 'outcome' => 'kept'],
        );
        $browser->post($v . '/modifications/new', self::form());
        $modification = $this->modification($app, $golf);
        $browser->post(
            $v . '/modifications/' . $modification->id . '/revert',
            ['reverted_on' => '2026-09-01', 'note' => 'For the MOT'],
        );

        $history = self::body($browser->get($v . '/history?kind=parts'));
        self::assertStringContainsString('Part fitted', $history);
        self::assertStringContainsString('Part removed', $history);
        self::assertStringContainsString('Kept', $history);
        self::assertStringContainsString('Modification reverted', $history);
        self::assertStringContainsString('For the MOT', $history);
        self::assertStringContainsString('removed Standard exhaust', $history);
        self::assertStringContainsString('Cat-back exhaust', self::body($browser->get($v . '/history/print')));
        self::assertStringContainsString('2026', self::body($browser->get($v . '/history?kind=parts&year=2026')));

        // The API carries the parts kinds since Phase 50.2, and names a reading's owner.
        $token = $this->apiKey($app, $this->owner($app));
        $api = $this->api($app, $token);
        self::assertSame(200, $api->get('/history?kinds=modification')->getStatusCode());
        $readings = self::body($api->get('/vehicles/' . $golf->id . '/odometer'));
        self::assertStringContainsString('"source":"modification"', $readings);
        self::assertStringContainsString('"source":"part_fitted"', $readings);
    }

    public function testAPartSoldForMoreThanTheWorkCostNeverDrawsANegativeBar(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $v = '/vehicles/' . $golf->id;
        $this->reading($app, $golf, '10000', '2026-01-02T12:00:00Z');
        $this->maintenance($app, $golf, '2026-03-01', 'Service', '200.000', '11000.000');
        $this->reading($app, $golf, '20000', '2026-10-01T12:00:00Z');
        // Free work, the old exhaust sold for £150: the build group is net −£150.
        $browser->post($v . '/modifications/new', self::form([
            'cost' => '0', 'odometer' => '', 'new_0_name' => '', 'new_0_brand' => '', 'new_0_part_number' => '',
            'new_0_price' => '', 'new_0_warranty_months' => '', 'off_0_outcome' => 'sold', 'off_0_sold_price' => '150',
        ]));

        foreach (['/reports?vehicle=' . $golf->id . '&range=all', $v . '/expenses?range=all', '/', $v . '/ownership'] as $page) {
            $body = self::body($browser->get($page));
            self::assertDoesNotMatchRegularExpression('/--w:\s*-/', $body, $page);
        }
        $ownership = self::body($browser->get($v . '/ownership'));
        self::assertStringContainsString('Modifications', $ownership);
        self::assertStringContainsString('never in a cost per distance', $ownership, 'the working says so');
    }

    public function testValidationAndAddAnotherRow(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/modifications/new';

        $future = $browser->post($base, self::form(['done_on' => '2026-10-20']));
        self::assertSame(422, $future->getStatusCode());
        self::assertStringContainsString("be after today", self::body($future));

        $price = $browser->post($base, self::form(['off_0_sold_price' => '100']));
        self::assertSame(422, $price->getStatusCode(), 'a sale price only goes with Sold');

        $more = $browser->post($base, self::form(['parts_more' => 'new']));
        self::assertSame(200, $more->getStatusCode());
        self::assertStringContainsString('name="new_1_name"', self::body($more));
        self::assertSame([], $this->service($app, ModificationRepository::class)->listForVehicles([$golf->id]), 'nothing saved');

        $sold = $browser->post($base, self::form(['off_0_outcome' => 'sold', 'off_0_sold_price' => '150']));
        self::assertSame(303, $sold->getStatusCode());
        self::assertSame(PartStatus::Gone, $this->parts($app, $golf)['Standard exhaust']->status);
    }

    public function testAServiceRecordFitsAPartAndItsDeleteNamesIt(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = (string) $golf->id;
        $v = '/vehicles/' . $id;

        $form = self::body($browser->get($v . '/maintenance/new'));
        self::assertStringContainsString('name="parts_lists"', $form);
        $saved = $browser->post($v . '/maintenance/new', [
            'title' => 'Clutch replaced', 'category' => 'repair', 'performed_on' => '2026-06-01', 'cost' => '780',
            'parts_lists' => '1', 'new_rows' => '1', 'off_rows' => '1', 'new_0_name' => 'Clutch kit', 'new_0_brand' => 'LuK',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        $clutch = $this->parts($app, $golf)['Clutch kit'];
        self::assertSame(PartStatus::Fitted, $clutch->status);

        $record = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id)[0];
        $confirm = self::body($browser->get($v . '/maintenance/' . $record->id . '/delete'));
        self::assertStringContainsString('LuK Clutch kit', $confirm);
        self::assertSame(303, $browser->post($v . '/maintenance/' . $record->id . '/delete', [])->getStatusCode());
        self::assertSame(PartStatus::Stored, $this->parts($app, $golf)['Clutch kit']->status, 'the part stays');
    }

    public function testFitRemoveAndRevert(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = (string) $golf->id;
        $v = '/vehicles/' . $id;

        $added = $browser->post($v . '/parts/new', [
            'name' => 'Roof bars', 'condition' => 'used', 'on_car' => '1', 'fit_on' => '2026-01-10', 'fit_odometer' => '30000',
        ]);
        self::assertSame(303, $added->getStatusCode());
        $bars = $this->parts($app, $golf)['Roof bars'];
        self::assertSame(PartStatus::Fitted, $bars->status);

        $remove = $v . '/parts/' . $bars->id . '/remove';
        self::assertStringContainsString('name="outcome"', self::body($browser->get($remove)));
        self::assertSame(303, $browser->post($remove, ['removed_on' => '2026-03-01', 'outcome' => 'kept'])->getStatusCode());
        self::assertSame(PartStatus::Stored, $this->parts($app, $golf)['Roof bars']->status);
        $refused = $browser->post($v . '/parts/' . $bars->id . '/fit', ['fit_on' => '2026-02-01']);
        self::assertSame(422, $refused->getStatusCode(), 'overlapping the spell before');
        self::assertStringContainsString('Roof bars would be on the car twice', self::body($refused));
        self::assertSame(303, $browser->post($v . '/parts/' . $bars->id . '/fit', ['fit_on' => '2026-04-01'])->getStatusCode());

        $browser->post($v . '/modifications/new', self::form());
        $modification = $this->modification($app, $golf);
        $revert = $v . '/modifications/' . $modification->id . '/revert';
        $parts = $this->parts($app, $golf);
        $page = self::body($browser->get($revert));
        self::assertStringContainsString('name="remove_' . $parts['Cat-back system']->id . '"', $page);
        self::assertStringContainsString('name="refit_' . $parts['Standard exhaust']->id . '"', $page);
        $reverted = $browser->post($revert, [
            'reverted_on' => '2026-09-01',
            'remove_' . $parts['Cat-back system']->id => '1',
            'remove_' . $parts['Cat-back system']->id . '_outcome' => 'kept',
            'refit_' . $parts['Standard exhaust']->id => '1',
        ]);
        self::assertSame(303, $reverted->getStatusCode());
        $parts = $this->parts($app, $golf);
        self::assertSame(PartStatus::Stored, $parts['Cat-back system']->status);
        self::assertSame(PartStatus::Fitted, $parts['Standard exhaust']->status);
        self::assertStringContainsString('Reverted', self::body($browser->get($v . '/modifications/' . $modification->id)));

        self::assertSame(303, $browser->post($v . '/modifications/' . $modification->id . '/unrevert', [])->getStatusCode());
        self::assertSame(PartStatus::Fitted, $this->parts($app, $golf)['Cat-back system']->status);

        $confirm = self::body($browser->get($v . '/modifications/' . $modification->id . '/delete'));
        self::assertStringContainsString('Milltek Cat-back system', $confirm);
        self::assertSame(303, $browser->post($v . '/modifications/' . $modification->id . '/delete', [])->getStatusCode());
        self::assertSame([], $this->service($app, ModificationRepository::class)->listForVehicles([$golf->id]));
    }

    public function testMakeThisAModification(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = (string) $golf->id;
        $v = '/vehicles/' . $id;
        $record = $this->maintenance($app, $golf, '2026-04-01', 'Coilovers fitted', '1200.000', '35000.000');
        $expense = $this->expense($app, $golf, '2026-02-01', '35.00', ExpenseCategory::Accessories, 'LED bulbs');

        $edit = self::body($browser->get($v . '/maintenance/' . $record->id . '/edit'));
        self::assertStringContainsString('/maintenance/' . $record->id . '/modification', $edit);
        $form = self::body($browser->get($v . '/maintenance/' . $record->id . '/modification'));
        self::assertStringContainsString('value="Coilovers fitted"', $form);
        $made = $browser->post($v . '/maintenance/' . $record->id . '/modification', [
            'title' => 'Coilovers', 'category' => 'suspension', 'done_on' => '2026-04-01', 'odometer' => '35000',
            'cost' => '1200',
        ]);
        self::assertSame(303, $made->getStatusCode());
        self::assertSame([], $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id));

        $made = $browser->post($v . '/expenses/' . $expense->id . '/modification', [
            'title' => 'LED bulbs', 'category' => 'lighting', 'done_on' => '2026-02-01', 'cost' => '35',
        ]);
        self::assertSame(303, $made->getStatusCode());
        self::assertCount(2, $this->service($app, ModificationRepository::class)->listForVehicles([$golf->id]));
    }

    public function testBuildSpendIsInTheTotalButNeverInTheCostPerDistance(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = (string) $golf->id;
        $v = '/vehicles/' . $id;
        $this->reading($app, $golf, '10000', '2026-01-01T12:00:00Z');
        $this->maintenance($app, $golf, '2026-03-01', 'Service', '200.000', '11000.000');
        $this->reading($app, $golf, '12000', '2026-09-01T12:00:00Z');

        $before = self::body($browser->get('/reports?vehicle=' . $id . '&range=all'));
        $browser->post($v . '/modifications/new', self::form(['done_on' => '2026-05-10', 'odometer' => '', 'cost' => '1000']));
        $after = self::body($browser->get('/reports?vehicle=' . $id . '&range=all'));

        self::assertStringContainsString('£200.00', $before);
        self::assertStringContainsString('£1,200.00', $after, 'in the total');
        self::assertStringContainsString('Modifications', $after);
        self::assertStringContainsString('Running costs only: modifications left out', $after);
        // £200 over 2,000 km either way: the build never moves the rate.
        preg_match_all('/£0\.\d+\/mi/', $before, $rateBefore);
        preg_match_all('/£0\.\d+\/mi/', $after, $rateAfter);
        self::assertNotSame([], $rateBefore[0]);
        self::assertSame($rateBefore[0][0], $rateAfter[0][0]);

        $build = self::body($browser->get('/reports?vehicle=' . $id . '&range=all&group=build'));
        self::assertStringContainsString('£1,000.00', $build);
        self::assertSame(
            [],
            preg_match_all('/£\d+\.\d+\/mi/', $build, $none) > 0 ? $none[0] : [],
            'no cost per distance for build',
        );
    }

    public function testAViewShareSeesButCannotChangeAndCostsNeedViewCosts(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = (string) $golf->id;
        $v = '/vehicles/' . $id;
        $browser->post($v . '/modifications/new', self::form());
        $modification = $this->modification($app, $golf);
        $viewer = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'viewer');

        $page = self::body($theirs->get($v . '/modifications/' . $modification->id));
        self::assertStringContainsString('Cat-back exhaust', $page);
        self::assertStringNotContainsString('Revert', $page);
        self::assertStringNotContainsString('£899', $page, 'no costs without Can see costs');
        self::assertSame(403, $theirs->get($v . '/modifications/new')->getStatusCode());
        self::assertSame(403, $theirs->post($v . '/modifications/' . $modification->id . '/revert', [])->getStatusCode());
    }

    /**
     * A Log share without *Can see costs* adds a part; the owner sells it and
     * makes a receipted expense a modification. The member sees neither the
     * sale price (nobody typed it as theirs) nor the receipt, which is a
     * cost file wherever it moves (spec.md §7.24).
     */
    public function testAMemberWithoutCostsSeesNoSalePriceAndNoConvertedReceipt(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $v = '/vehicles/' . $golf->id;
        $zone = new DateTimeZone('UTC');
        // The owner's receipt, made a modification before the member does anything.
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-upload-');
        file_put_contents($path, "%PDF-1.4\n%receipt\n");
        $file = new UploadedFile($path, 'receipt.pdf', 'application/pdf', (int) filesize($path), UPLOAD_ERR_OK);
        $expense = $this->service($app, ExpenseService::class)->create(
            $golf,
            new ExpenseEntryData(new DateTimeImmutable('2026-03-01'), ExpenseCategory::Other, '450.000', 'Remap'),
            new PendingUploads([new PendingUpload($file, FileUpload::check($file, 1024 * 1024, UploadKind::Document))]),
        );
        $remap = $this->service($app, ModificationService::class)->fromExpense($golf, $expense, new ModificationData(
            'Stage 1 remap',
            ModificationCategory::Other,
            new DateTimeImmutable('2026-03-01'),
            null,
            '450.000',
        ), $zone);
        $member = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'logger');
        $theirs->post($v . '/parts/new', ['name' => 'Roof box', 'fit_now' => '1', 'fit_on' => '2026-02-01']);
        $roofBox = $this->parts($app, $golf)['Roof box'];
        self::assertSame($member->id, $roofBox->createdBy, 'the member added it');
        $parts = $this->service($app, PartService::class);
        if ($parts->get($golf, $roofBox->id)->status !== PartStatus::Fitted) {
            $parts->fit($golf, $parts->get($golf, $roofBox->id), new DateTimeImmutable('2026-02-01'), null, $zone);
        }
        $parts->remove(
            $golf,
            $parts->get($golf, $roofBox->id),
            new DateTimeImmutable('2026-06-01'),
            null,
            new PartRemoval(RemovalOutcome::Sold, '4321.000'),
            $zone,
        );

        foreach (['/parts/' . $roofBox->id, '/expenses?range=all'] as $page) {
            $body = self::body($theirs->get($v . $page));
            self::assertStringNotContainsString('4,321', $body, $page);
            self::assertStringNotContainsString('4321', $body, $page);
        }
        $receipt = $this->service($app, AttachmentRepository::class)->listForVehicle($golf->id)[0];
        self::assertSame('modification', $receipt->ownerType->value);
        self::assertSame($remap->id, $receipt->ownerId);
        self::assertNotSame($member->id, $receipt->uploadedBy);
        self::assertStringNotContainsString('receipt.pdf', self::body($theirs->get($v . '/modifications/' . $remap->id)));
        self::assertContains(
            $theirs->get($v . '/attachments/' . $receipt->id)->getStatusCode(),
            [403, 404],
            'the receipt stays a cost file',
        );
    }

    /**
     * A part's price and a modification's cost need *Can see costs*, never
     * the own-entry rule: a member without it doesn't type them, so adding
     * the record doesn't make the amount theirs. What they send anyway is
     * ignored, and their edits keep the owner's figures (spec.md §7.39).
     */
    public function testPricesAndCostsNeedCanSeeCostsEvenOnTheMembersOwnRecords(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $owner = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $v = '/vehicles/' . $golf->id;
        $member = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'logger');

        // What they send is ignored: no price, a free modification.
        $theirs->post($v . '/parts/new', ['name' => 'Roof box', 'price' => '55']);
        $theirs->post($v . '/modifications/new', self::towBar(['cost' => '66']));
        $roofBox = $this->parts($app, $golf)['Roof box'];
        $towBar = $this->modification($app, $golf);
        self::assertNull($roofBox->data->price);
        self::assertSame('0.000', $towBar->data->cost);

        // The owner types the amounts; the member's pages never show them.
        $owner->post($v . '/parts/' . $roofBox->id . '/edit', ['name' => 'Roof box', 'price' => '1234.56']);
        $owner->post($v . '/modifications/' . $towBar->id . '/edit', self::towBar(['cost' => '7777']));
        self::assertSame('1234.560', $this->part($app, $golf, $roofBox->id)->data->price);
        self::assertSame('7777.000', $this->modification($app, $golf)->data->cost);
        $pages = [
            '/parts/' . $roofBox->id, '/modifications/' . $towBar->id, '/parts?status=all', '',
            '/history?kind=parts', '/history/print?costs=1',
        ];
        foreach ($pages as $page) {
            $body = self::body($theirs->get($v . $page));
            self::assertStringNotContainsString('1,234.56', $body, $page);
            self::assertStringNotContainsString('7,777', $body, $page);
        }

        // Their edits keep the owner's figures.
        $theirs->post($v . '/parts/' . $roofBox->id . '/edit', ['name' => 'Roof box XL']);
        $theirs->post($v . '/modifications/' . $towBar->id . '/edit', self::towBar(['title' => 'Tow bar (detachable)']));
        self::assertSame('1234.560', $this->part($app, $golf, $roofBox->id)->data->price);
        self::assertSame('7777.000', $this->modification($app, $golf)->data->cost);

        // A sale they record has no price, so no hidden line in the owner's figures.
        $parts = $this->service($app, PartService::class);
        $on = new DateTimeImmutable('2026-02-01');
        $parts->fit($golf, $this->part($app, $golf, $roofBox->id), $on, null, new DateTimeZone('UTC'));
        $sold = $theirs->post($v . '/parts/' . $roofBox->id . '/remove', [
            'removed_on' => '2026-06-01', 'outcome' => 'sold', 'sold_price' => '9999',
        ]);
        self::assertSame(303, $sold->getStatusCode(), self::body($sold));
        $fittings = $parts->fittings($this->part($app, $golf, $roofBox->id));
        self::assertSame(RemovalOutcome::Sold, $fittings[count($fittings) - 1]->outcome);
        self::assertContains($fittings[count($fittings) - 1]->soldPrice, [null, '0.000'], 'not the 9,999 they sent');

        // The owner, with costs, sees them.
        self::assertStringContainsString('1,234.56', self::body($owner->get($v . '/parts/' . $roofBox->id)));
        self::assertStringContainsString('7,777', self::body($owner->get($v . '/modifications/' . $towBar->id)));
    }

    /**
     * A modification without parts lists.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function towBar(array $overrides = []): array
    {
        return $overrides + ['title' => 'Tow bar', 'category' => 'other', 'done_on' => '2026-05-10'];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function part(App $app, Vehicle $vehicle, int $id): Part
    {
        return $this->service($app, PartService::class)->get($vehicle, $id);
    }

    /**
     * *Undo revert* rewrites the record and can take back a sale price
     * someone else typed, so it follows `EntryAccess` like editing; *Revert*
     * stays `Log` (spec.md §7.39 *Access*, #430).
     */
    public function testUndoRevertNeedsTheRightToChangeTheModificationButRevertIsLog(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $owner = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $v = '/vehicles/' . $golf->id;
        $zone = new DateTimeZone('UTC');
        $mods = $this->service($app, ModificationService::class);
        $parts = $this->service($app, PartService::class);
        $done = new DateTimeImmutable('2026-03-01');
        $coilovers = $mods->create(
            $golf,
            new ModificationData('Coilovers', ModificationCategory::Other, $done, null, '800.000'),
            $zone,
            parts: new RecordParts(fitNew: [new PartData('Coilovers')]),
        );
        $wing = $mods->create(
            $golf,
            new ModificationData('Rear wing', ModificationCategory::Other, $done, null, '150.000'),
            $zone,
        );
        $part = $parts->list($golf)[0];
        $sold = [$part->id => new PartRemoval(RemovalOutcome::Sold, '5555.000')];
        $mods->revert($golf, $coilovers, new DateTimeImmutable('2026-06-01'), null, null, $sold, [], $zone);
        $member = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'logger');

        $page = self::body($theirs->get($v . '/modifications/' . $coilovers->id));
        self::assertStringNotContainsString('/unrevert', $page, 'no Undo revert on the owner\'s modification');
        self::assertSame(403, $theirs->post($v . '/modifications/' . $coilovers->id . '/unrevert', [])->getStatusCode());
        $fittings = $parts->fittings($parts->get($golf, $part->id));
        self::assertSame('5555.000', $fittings[count($fittings) - 1]->soldPrice, 'the owner\'s sale stays');
        self::assertTrue($mods->get($golf, $coilovers->id)->isReverted());

        // Revert stays Log: the member is offered it on the owner's modification, and it works.
        $wingPage = self::body($theirs->get($v . '/modifications/' . $wing->id));
        self::assertStringContainsString('/modifications/' . $wing->id . '/revert', $wingPage);
        self::assertStringNotContainsString('/modifications/' . $wing->id . '/edit', $wingPage);
        $reverted = $theirs->post($v . '/modifications/' . $wing->id . '/revert', ['reverted_on' => '2026-07-01']);
        self::assertSame(303, $reverted->getStatusCode(), self::body($reverted));
        self::assertTrue($mods->get($golf, $wing->id)->isReverted());

        // The owner may undo.
        self::assertSame(303, $owner->post($v . '/modifications/' . $coilovers->id . '/unrevert', [])->getStatusCode());
        self::assertFalse($mods->get($golf, $coilovers->id)->isReverted());
    }

    /**
     * *Make this a modification* from text longer than a modification's
     * title keeps the first 120 characters as the title and the whole text
     * in *Notes*, so deleting the record loses nothing (spec.md §7.39, #431).
     */
    public function testConvertingKeepsTextLongerThanTheTitleInTheNotes(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $v = '/vehicles/' . $golf->id;
        $note = str_repeat('Long text ', 49) . 'end';
        $expense = $this->service($app, ExpenseService::class)->create(
            $golf,
            new ExpenseEntryData(new DateTimeImmutable('2026-03-01'), ExpenseCategory::Parking, '12.345', $note),
        );
        $title = str_repeat('Clutch and flywheel ', 7) . 'xxxxxxxxxx';
        self::assertSame(150, mb_strlen($title));
        $description = str_repeat('d', 2000);
        $record = $this->service($app, MaintenanceService::class)->create(
            $golf,
            new MaintenanceEntryData(
                new DateTimeImmutable('2026-04-01'),
                MaintenanceCategory::Repair,
                $title,
                '900.000',
                description: $description,
            ),
            new DateTimeZone('UTC'),
        );

        $cases = [
            [$v . '/expenses/' . $expense->id . '/modification', $note, $note],
            [$v . '/maintenance/' . $record->id . '/modification', $title, $title . "\n\n" . $description],
        ];
        foreach ($cases as [$path, $text, $notes]) {
            $page = self::body($browser->get($path));
            $form = self::convertForm($page);
            self::assertSame(mb_substr($text, 0, 120), $form['title'], $path);
            self::assertSame($notes, $form['notes'], $path . ': the whole text in Notes');
            $saved = $browser->post($path, $form);
            self::assertSame(303, $saved->getStatusCode(), $path . ' ' . self::body($saved));
        }
        $made = $this->service($app, ModificationRepository::class)->listForVehicles([$golf->id]);
        $byNotes = [];
        foreach ($made as $modification) {
            $byNotes[] = $modification->data->notes;
        }
        self::assertContains($note, $byNotes);
        self::assertContains($title . "\n\n" . $description, $byNotes);
    }

    /**
     * A service record saved through the API has LF line breaks; the browser
     * posts the convert form's back as CRLF. They count once, so the unchanged
     * form saves, and a Notes error shows on the field (#433).
     */
    public function testConvertingTextWithLineBreaksSavesAndANotesErrorShowsOnTheField(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $v = '/vehicles/' . $golf->id;
        $title = str_repeat('é', 150);
        $description = mb_substr(str_repeat(str_repeat('d', 38) . "\n", 50) . str_repeat('x', 100), 0, 2000);
        $maintenance = $this->service($app, MaintenanceService::class);
        $records = [];
        foreach (['2026-04-01', '2026-04-02'] as $on) {
            $records[] = $maintenance->create(
                $golf,
                new MaintenanceEntryData(
                    new DateTimeImmutable($on),
                    MaintenanceCategory::Repair,
                    $title,
                    '900.000',
                    description: $description,
                ),
                new DateTimeZone('UTC'),
            );
        }

        $path = $v . '/maintenance/' . $records[0]->id . '/modification';
        $form = self::convertForm(self::body($browser->get($path)));
        $form['notes'] = str_replace("\n", "\r\n", $form['notes']);
        $saved = $browser->post($path, $form);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        $made = $this->service($app, ModificationRepository::class)->listForVehicles([$golf->id]);
        self::assertSame($title . "\n\n" . $description, $made[0]->data->notes, 'stored with LF');

        $path = $v . '/maintenance/' . $records[1]->id . '/modification';
        $form = self::convertForm(self::body($browser->get($path)));
        $form['notes'] .= str_repeat('y', 100);
        $refused = $browser->post($path, $form);
        self::assertSame(422, $refused->getStatusCode());
        $page = self::body($refused);
        self::assertStringContainsString('id="f-notes-error"', $page);
        self::assertMatchesRegularExpression('/name="notes"[^>]*aria-invalid="true"/', $page);
    }

    /**
     * The convert page's title and notes as the browser would post them, plus its other fields.
     *
     * @return array<string, string>
     */
    private static function convertForm(string $page): array
    {
        $form = ['category' => 'other'];
        foreach (['title', 'done_on', 'odometer', 'cost', 'fitted_by'] as $field) {
            if (preg_match('/name="' . $field . '"[^>]*value="([^"]*)"/', $page, $m) === 1) {
                $form[$field] = html_entity_decode($m[1], ENT_QUOTES);
            }
        }
        preg_match('/<textarea[^>]*name="notes"[^>]*>(.*?)<\/textarea>/s', $page, $m);
        $form['notes'] = html_entity_decode($m[1] ?? '', ENT_QUOTES);

        return $form;
    }

    public function testAnArchivedVehicleIsReadOnlyAndASubpathWorks(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = (string) $golf->id;
        $v = '/vehicles/' . $id;
        $created = $browser->post('/logbook' . $v . '/modifications/new', self::form());
        $modification = $this->modification($app, $golf);
        self::assertSame('/logbook' . $v . '/modifications/' . $modification->id, $created->getHeaderLine('Location'));
        $page = self::body($browser->get($v . '/modifications/' . $modification->id));
        self::assertStringContainsString('href="/logbook/vehicles/' . $id . '/parts"', $page);

        $this->service($app, VehicleService::class)->archive($this->owner($app), $golf);
        self::assertSame(200, $browser->get($v . '/modifications/' . $modification->id)->getStatusCode());
        self::assertStringNotContainsString('/revert', self::body($browser->get($v . '/modifications/' . $modification->id)));
        self::assertSame(303, $browser->post($v . '/modifications/new', self::form())->getStatusCode());
        self::assertCount(1, $this->service($app, ModificationRepository::class)->listForVehicles([$golf->id]), 'nothing added');
    }

    public function testTheModuleSwitchedOffIs404AndItsLinesLeaveTheReports(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $id = (string) $golf->id;
        $v = '/vehicles/' . $id;
        $browser->post($v . '/modifications/new', self::form());
        $modification = $this->modification($app, $golf);
        $part = $this->parts($app, $golf)['Cat-back system'];

        $toggles = $this->service($app, FeatureToggles::class);
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Parts)));

        foreach (
            [
                '/parts', '/parts/new', '/parts/' . $part->id, '/parts/' . $part->id . '/edit', '/modifications/new',
                '/modifications/' . $modification->id, '/modifications/' . $modification->id . '/revert',
            ] as $path
        ) {
            self::assertSame(404, $browser->get('/vehicles/' . $id . $path)->getStatusCode(), $path);
        }
        self::assertStringNotContainsString('/parts"', self::body($browser->get('/vehicles/' . $id)));
        self::assertStringNotContainsString('/log/new/modification', self::body($browser->get('/log/new')));
        self::assertStringNotContainsString('parts_lists', self::body($browser->get($v . '/maintenance/new')));
        self::assertStringNotContainsString('£899', self::body($browser->get('/reports?vehicle=' . $id . '&range=all')));
    }
}
