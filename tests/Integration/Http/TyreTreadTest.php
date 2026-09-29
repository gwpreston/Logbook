<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyreStatus;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\UserRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Tyre\TyreSettingsStore;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\DepthUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Tread depth through HTTP (spec.md §7.17): *Check tread* as a page and in
 * the modal, depths on the other forms, the "deeper than last time" notice,
 * Settings → Tyres in 32nds, the print header and CSV. Every form is a plain
 * POST, so these are also the no-JS paths.
 */
final class TyreTreadTest extends AppTestCase
{
    use CostFixtures;

    private const array MODAL = ['X-Logbook-Modal' => '1'];

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private Vehicle $car;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->browser = $this->signedIn($this->app);
        $this->car = $this->vehicle($this->app);
        $this->reading($this->app, $this->car, '32186.880', '2026-09-01T09:00:00Z');
        $this->base = '/vehicles/' . $this->car->id . '/tyres';
    }

    /**
     * Fit two Michelins at the front at 8 mm (no-JS post), at 20,000 miles.
     */
    private function fitFronts(string $tread = '8'): void
    {
        $response = $this->browser->post($this->base . '/fit', [
            'done_on' => '2026-01-10',
            'odometer' => '20000',
            'pos_fl' => '1',
            'pos_fr' => '1',
            'brand' => 'Michelin',
            'model' => 'Primacy 4',
            'tread' => $tread,
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
    }

    /**
     * @return array<string, Tyre> position → fitted tyre
     */
    private function fitted(): array
    {
        $fitted = [];
        foreach ($this->service($this->app, TyreService::class)->tyres($this->car) as $tyre) {
            if ($tyre->status === TyreStatus::Fitted && $tyre->position !== null) {
                $fitted[$tyre->position->value] = $tyre;
            }
        }

        return $fitted;
    }

    private function latest(): TyreChange
    {
        $changes = $this->service($this->app, TyreService::class)->changes($this->car);
        self::assertNotEmpty($changes);

        return $changes[0];
    }

    /**
     * @return array<int, ?string> tyre id → depth on the change's line
     */
    private static function depths(TyreChange $change): array
    {
        $depths = [];
        foreach ($change->lines as $line) {
            $depths[$line->tyreId] = $line->treadMm;
        }

        return $depths;
    }

    private function useThirtySeconds(): void
    {
        $users = $this->service($this->app, UserRepository::class);
        $owner = $users->findByUsername('owner');
        self::assertNotNull($owner);
        $prefs = $owner->preferences;
        $users->updateProfile($owner->id, $owner->displayName, new DisplayPreferences(
            $prefs->locale,
            $prefs->timezone,
            $prefs->distanceUnit,
            $prefs->volumeUnit,
            $prefs->consumptionUnit,
            $prefs->currency,
            $prefs->theme,
            $prefs->accent,
            DepthUnit::ThirtySecond,
        ), new DateTimeImmutable());
    }

    public function testFitRecordsTheDepthWhenNewOnEveryPosition(): void
    {
        $this->fitFronts('8.1');

        $change = $this->latest();
        self::assertSame(TyreChangeKind::Fit, $change->kind);
        self::assertSame(['8.100', '8.100'], array_values(self::depths($change)));
        $html = self::body($this->browser->get($this->base));
        self::assertStringContainsString('8.1 mm on 10 Jan 2026', $html, 'the card shows the latest depth and date');
        self::assertStringContainsString('Fitted 2 × Michelin Primacy 4 (front) · 8.1 mm', $html, 'depths follow the summary');
    }

    public function testCheckTreadAsAPageAndInTheModal(): void
    {
        $this->fitFronts();
        $day = LocalTime::parseDate('2026-09-20');
        self::assertNotNull($day);
        $this->service($this->app, MaintenanceService::class)->create(
            $this->car,
            new MaintenanceEntryData($day, MaintenanceCategory::Tyres, 'Tyre check', '0'),
            new DateTimeZone('Europe/London'),
        );
        $fitted = $this->fitted();

        $page = $this->browser->get($this->base . '/check');
        self::assertSame(200, $page->getStatusCode());
        $html = self::body($page);
        self::assertStringContainsString('name="tread_' . $fitted['fl']->id . '"', $html);
        self::assertLessThan(
            strpos($html, 'name="tread_' . $fitted['fr']->id . '"'),
            strpos($html, 'name="tread_' . $fitted['fl']->id . '"'),
            'in position order',
        );
        self::assertStringContainsString('Front left · Michelin Primacy 4', $html, 'labelled with position and tyre');
        self::assertStringNotContainsString('name="link"', $html, 'a check links no service record');
        self::assertStringNotContainsString('name="cost"', $html);
        $modal = self::body($this->browser->get($this->base . '/check', self::MODAL));
        self::assertStringNotContainsString('<nav class="tabs"', $modal, 'the modal renders only the form');

        // Without JS: a plain POST, redirected back to the tab.
        $saved = $this->browser->post($this->base . '/check', [
            'done_on' => '2026-06-01',
            'odometer' => '25000',
            'tread_' . $fitted['fl']->id => '6.3',
            'tread_' . $fitted['fr']->id => '',
        ]);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        $check = $this->latest();
        self::assertSame(TyreChangeKind::Check, $check->kind);
        self::assertSame([$fitted['fl']->id => '6.300'], self::depths($check), 'a blank tyre is not measured');
        self::assertNull($check->data->maintenanceEntryId);
        self::assertEquals($fitted, $this->fitted(), 'nothing moved');
        $tab = self::body($this->browser->follow($saved));
        self::assertStringContainsString('Tread depths saved.', $tab);
        self::assertStringContainsString('Checked tread: 6.3 mm', $tab);

        // In the modal a save closes the dialog.
        $modalSave = $this->browser->post($this->base . '/check', [
            'done_on' => '2026-07-01',
            'odometer' => '26000',
            'tread_' . $fitted['fr']->id => '6',
        ], headers: self::MODAL);
        self::assertSame(204, $modalSave->getStatusCode());
    }

    public function testCheckTreadErrorsKeepWhatWasTyped(): void
    {
        $this->fitFronts();
        $fitted = $this->fitted();

        $none = $this->browser->post($this->base . '/check', ['done_on' => '2026-06-01', 'odometer' => '25000']);
        self::assertSame(422, $none->getStatusCode());
        self::assertStringContainsString('Enter at least one depth.', self::body($none));

        $bad = $this->browser->post($this->base . '/check', [
            'done_on' => '2026-06-01',
            'odometer' => '',
            'tread_' . $fitted['fl']->id => '21',
            'tread_' . $fitted['fr']->id => '6.2',
        ]);
        self::assertSame(422, $bad->getStatusCode());
        $html = self::body($bad);
        self::assertStringContainsString('value="21"', $html);
        self::assertStringContainsString('value="6.2"', $html, 'the valid one is kept too');
        self::assertCount(1, $this->service($this->app, TyreService::class)->changes($this->car), 'nothing saved');

        $zero = $this->browser->post($this->base . '/check', [
            'done_on' => '2026-06-01',
            'odometer' => '25000',
            'tread_' . $fitted['fl']->id => '0',
        ]);
        self::assertSame(303, $zero->getStatusCode(), '0 is alarming, not impossible');
    }

    public function testADeeperReadingIsSavedWithANotice(): void
    {
        $this->fitFronts('6');
        $fl = $this->fitted()['fl'];

        $deeper = $this->browser->post($this->base . '/check', [
            'done_on' => '2026-06-01',
            'odometer' => '25000',
            'tread_' . $fl->id => '6.6',
        ]);
        self::assertSame(303, $deeper->getStatusCode());
        self::assertStringContainsString(
            'Michelin Primacy 4: deeper than last time (6.0 mm on 10 Jan 2026) — check the reading.',
            self::body($this->browser->follow($deeper)),
        );

        $close = $this->browser->post($this->base . '/check', [
            'done_on' => '2026-07-01',
            'odometer' => '26000',
            'tread_' . $fl->id => '7',
        ]);
        self::assertStringNotContainsString('deeper than last time', self::body($this->browser->follow($close)), '+0.4 mm');
    }

    public function testDepthsOnSwapAndRemove(): void
    {
        $this->fitFronts();
        $fitted = $this->fitted();

        $swap = $this->browser->post($this->base . '/swap', [
            'done_on' => '2026-10-01',
            'odometer' => '24000',
            'set' => 'new',
            'set_name' => 'Summer wheels',
            'tread_' . $fitted['fl']->id => '6.1',
            'tread_' . $fitted['fr']->id => '6.2',
        ]);
        self::assertSame(303, $swap->getStatusCode(), self::body($swap));
        self::assertSame([$fitted['fl']->id => '6.100', $fitted['fr']->id => '6.200'], self::depths($this->latest()));
        $stored = self::body($this->browser->get($this->base));
        self::assertStringContainsString('6.1 mm on 1 Oct 2026', $stored, 'stored tyres show their latest depth');

        $on = $this->browser->post($this->base . '/swap', [
            'done_on' => '2027-04-01',
            'odometer' => '26000',
            'on_' . $fitted['fl']->id => 'fl',
            'tread_' . $fitted['fl']->id => '6.1',
            'tread_' . $fitted['fr']->id => '5',
        ]);
        self::assertSame(303, $on->getStatusCode(), self::body($on));
        self::assertSame([$fitted['fl']->id => '6.100'], self::depths($this->latest()), 'only tyres the swap touches');

        $remove = $this->browser->post($this->base . '/remove', [
            'done_on' => '2027-05-01',
            'odometer' => '27000',
            'action_' . $fitted['fl']->id => 'worn',
            'tread_' . $fitted['fl']->id => '1.9',
        ]);
        self::assertSame(303, $remove->getStatusCode(), self::body($remove));
        self::assertSame([$fitted['fl']->id => '1.900'], self::depths($this->latest()));
        $retired = self::body($this->browser->get($this->base));
        self::assertStringContainsString('1.9 mm on 1 May 2027', $retired, 'a retired tyre shows its last depth');
    }

    public function testSettingsSaveInThirtySecondsAndReadBack(): void
    {
        $this->useThirtySeconds();
        $page = self::body($this->browser->get('/settings/tyres'));
        self::assertMatchesRegularExpression('~name="car_replace"[^>]*value="4"~', $page, '3.0 mm is shown as 4/32″');

        $saved = $this->browser->post('/settings/tyres', [
            'car_replace' => '4',
            'car_winter_replace' => '6.5',
            'car_legal' => '2.5',
            'bike_replace' => '3',
            'bike_legal' => '1.5',
            'age_years' => '8',
        ]);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        $owner = $this->service($this->app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);
        $thresholds = $this->service($this->app, TyreSettingsStore::class)->thresholds($owner->id);
        self::assertSame('3.000', $thresholds->carReplaceMm, 'unchanged: the stored millimetres are kept');
        self::assertSame('5.159', $thresholds->carWinterReplaceMm, '6½/32″');
        self::assertSame('1.984', $thresholds->carLegalMm, '2½/32″');
        self::assertSame('2.381', $thresholds->bikeReplaceMm, '2.0 mm shows as 2½/32″, so 3/32″ is a change');
        self::assertSame(8, $thresholds->ageYears);
        $again = self::body($this->browser->follow($saved));
        self::assertMatchesRegularExpression('~name="car_winter_replace"[^>]*value="6.5"~', $again);

        $bad = $this->browser->post('/settings/tyres', [
            'car_replace' => '4.3',
            'car_winter_replace' => '26',
            'car_legal' => '2',
            'bike_replace' => '3',
            'bike_legal' => '1.5',
            'age_years' => '16',
        ]);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('Use whole or half 32nds', self::body($bad));
    }

    public function testSettingsLinkAndPageFollowTheModule(): void
    {
        self::assertStringContainsString('href="/settings/tyres"', self::body($this->browser->get('/settings')));
        $this->service($this->app, FeatureToggles::class)->save([]);
        self::assertStringNotContainsString('href="/settings/tyres"', self::body($this->browser->get('/settings')));
        self::assertSame(404, $this->browser->get('/settings/tyres')->getStatusCode());
    }

    public function testThePrintHeaderShowsMeasuredDepthsNeverEstimates(): void
    {
        $this->fitFronts();
        $fitted = $this->fitted();
        $this->browser->post($this->base . '/check', [
            'done_on' => '2026-08-01',
            'odometer' => '26000',
            'tread_' . $fitted['fl']->id => '5.4',
            'tread_' . $fitted['fr']->id => '5.5',
        ]);

        self::assertStringContainsString('about', self::body($this->browser->get($this->base)), 'the tab has an estimate');
        $print = self::body($this->browser->get('/vehicles/' . $this->car->id . '/history/print'));
        self::assertStringContainsString('5.4 mm on 1 Aug 2026', $print);
        self::assertStringNotContainsString('mm now', $print, 'no estimate printed');
        self::assertStringNotContainsString('mi left', $print);
    }

    public function testTheDeleteConfirmationUsesTheOwnersDepthUnit(): void
    {
        $this->useThirtySeconds();
        $this->fitFronts('10');

        $page = self::body($this->browser->get($this->base . '/changes/' . $this->latest()->id . '/delete'));
        self::assertStringContainsString('10/32″', $page);
        self::assertStringNotContainsString('7.9 mm', $page);
    }

    public function testCsvCarriesTheDepths(): void
    {
        $this->useThirtySeconds();
        $this->fitFronts('10');
        $fitted = $this->fitted();
        $this->browser->post($this->base . '/check', [
            'done_on' => '2026-08-01',
            'odometer' => '26000',
            'tread_' . $fitted['fr']->id => '6.5',
        ]);
        $export = '/vehicles/' . $this->car->id . '/export/';

        $tyres = explode("\r\n", trim(substr(self::body($this->browser->get($export . 'tyres.csv')), 3)));
        self::assertStringEndsWith(
            'Latest depth (32nds of an inch),Latest depth on,Depth now (32nds of an inch),Distance left (Miles)',
            $tyres[0],
        );
        self::assertStringEndsWith(',10,2026-01-10,,', $tyres[1], 'one depth: no estimate yet');
        self::assertStringContainsString(',6.5,2026-08-01,6.5,', $tyres[2], 'measured today, so depth now is the reading');

        $changes = explode("\r\n", trim(substr(self::body($this->browser->get($export . 'tyre-changes.csv')), 3)));
        self::assertStringContainsString('Depths (32nds of an inch)', $changes[0]);
        self::assertStringContainsString('Front left; Front right,10; 10,', $changes[1]);
        self::assertStringContainsString(',Check tread,', $changes[2]);
        self::assertStringContainsString('Front right,6.5,', $changes[2]);
    }

    public function testHistoryListsTheCheck(): void
    {
        $this->fitFronts();
        $fitted = $this->fitted();
        $this->browser->post($this->base . '/check', [
            'done_on' => '2026-08-01',
            'odometer' => '26000',
            'tread_' . $fitted['fl']->id => '5.1',
            'tread_' . $fitted['fr']->id => '6.3',
        ]);

        $history = self::body($this->browser->get('/vehicles/' . $this->car->id . '/history'));
        self::assertStringContainsString('Checked tread: 5.1–6.3 mm', $history);
        self::assertStringContainsString('Tread check', self::body($this->browser->get('/')), 'and Recent activity');
    }
}
