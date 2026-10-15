<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Scan;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\PartRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\ScanFixture;
use Logbook\Tests\Support\ScanTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * *Add as parts* on a scanned service invoice (spec.md §7.27 *Parts lines*,
 * §7.39 *Scanning*, #440): the parts lines offered unticked with a name,
 * a part number and a price; only the ticked ones become parts, only on
 * *Save*, and a price only for those who may see costs (#429).
 */
final class ScanPartsTest extends ScanTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->scanApp(['FEATURES_PARTS' => 'true']);
    }

    public function testThePartsLinesAreOfferedUntickedAndNothingIsSavedByReading(): void
    {
        [$form] = $this->scanToForm();
        $html = self::body($form);

        self::assertStringContainsString('Add as parts', $html);
        $values = Html::formValues(Html::element(Html::document($html), 'form.form'));
        self::assertSame('3', $values['scan_part_rows']);
        self::assertSame('Engine oil 5W-30 4.5 litres', $values['scan_part_0_name']);
        self::assertSame('38.25', $values['scan_part_0_price']);
        self::assertSame('Oil filter', $values['scan_part_1_name']);
        self::assertSame('12.5', $values['scan_part_1_price']);
        foreach ([0, 1, 2] as $i) {
            self::assertArrayNotHasKey('scan_part_' . $i . '_add', $values, 'unticked by default');
        }
        self::assertStringContainsString('“Oil filter 12.50”', $html, 'the line it came from');
        self::assertSame(0, $this->parts(), 'nothing is saved by reading');
    }

    public function testOnlyTheTickedLinesBecomePartsFittedByTheRecord(): void
    {
        $golf = $this->garage['Golf'];
        [$form] = $this->scanToForm();
        $values = Html::formValues(Html::element(Html::document(self::body($form)), 'form.form'));

        $untouched = $this->browser->post('/vehicles/' . $golf->id . '/maintenance/new', $values);
        self::assertSame(303, $untouched->getStatusCode(), self::body($untouched));
        self::assertSame(0, $this->parts(), 'saving the record with nothing ticked creates no part');

        [$form] = $this->scanToForm();
        $values = Html::formValues(Html::element(Html::document(self::body($form)), 'form.form'));
        $values['scan_part_1_add'] = '1';
        $values['scan_part_1_name'] = 'Oil filter (Mann)';
        $values['scan_part_1_part_number'] = 'W 712/95';
        $saved = $this->browser->post('/vehicles/' . $golf->id . '/maintenance/new', $values);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));

        $entries = $this->service($this->app, MaintenanceEntryRepository::class)->listForVehicle($golf->id);
        $record = $entries[count($entries) - 1];
        $parts = $this->service($this->app, PartRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $parts, 'one line ticked, one part');
        self::assertSame('Oil filter (Mann)', $parts[0]->data->name);
        self::assertSame('W 712/95', $parts[0]->data->partNumber);
        self::assertSame('12.500', $parts[0]->data->price);
        self::assertNull($parts[0]->data->warrantyMonths, 'a warranty is never read from a line');
        self::assertFalse($parts[0]->data->warrantyReminder);
        $fittings = $this->service($this->app, PartRepository::class)->fittingsOf($parts[0]->id);
        self::assertSame($record->id, $fittings[0]->fittedMaintenanceId, 'fitted by the scanned record');
    }

    public function testAMemberWithoutCostsIsOfferedTheLinesWithoutPrices(): void
    {
        $golf = $this->garage['Golf'];
        $member = $this->createMember($this->app, 'logger');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($this->app, 'logger');

        $this->reply(self::reading());
        $file = $this->upload('%PDF-1.4 test', 'invoice.jpg', 'image/jpeg');
        $response = $theirs->post('/scan', ['vehicle' => (string) $golf->id], ['file' => $file]);
        [$page] = $this->landAs($theirs, $response);
        $html = self::body($page);

        self::assertStringContainsString('Add as parts', $html);
        self::assertStringContainsString('Oil filter', $html);
        self::assertStringNotContainsString('scan_part_1_price', $html, 'a price needs Can see costs');
        self::assertStringNotContainsString('name="scan_part_0_price"', $html);
    }

    public function testTheListIsGoneWithThePartsModuleOff(): void
    {
        $without = array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Parts));
        $this->service($this->app, FeatureToggles::class)->save($without);
        [$form] = $this->scanToForm();

        self::assertStringNotContainsString('Add as parts', self::body($form));
    }

    /**
     * @return array{ResponseInterface}
     */
    private function scanToForm(): array
    {
        $this->reply(self::reading());
        [$page] = $this->land($this->scan(\Logbook\Tests\Support\ExifJpeg::make(40, 20, 6), 'invoice.jpg', 'image/jpeg'));
        self::assertSame(200, $page->getStatusCode());

        return [$page];
    }

    /**
     * @return array{ResponseInterface, list<string>}
     */
    private function landAs(\Logbook\Tests\Support\TestBrowser $browser, ResponseInterface $response): array
    {
        $path = [];
        while (in_array($response->getStatusCode(), [302, 303], true)) {
            $path[] = $response->getHeaderLine('Location');
            $response = $browser->follow($response);
        }

        return [$response, $path];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reading(): array
    {
        return ScanFixture::load('01-service-invoice')->reply;
    }

    private function parts(): int
    {
        $count = $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM parts');

        return is_numeric($count) ? (int) $count : 0;
    }
}
