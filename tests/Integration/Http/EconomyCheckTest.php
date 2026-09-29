<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Economy checks end to end (spec.md §7.3): the flags on the Fuel tab and
 * `?check=1`, *Looks right* / *Undo* with and without JS, the save notice,
 * the edit page, the dashboard's *Recent fuel*, and nothing at all with the
 * `fuel` module off. The owner uses UK units (miles, litres, mpg UK).
 */
final class EconomyCheckTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    private int $day = 0;
    private int $km = 10000;

    public function testTheFuelTabFlagsAMistypedOdometerAsAPair(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        [$opening, $typo, $after] = $this->pair($app, $golf);
        $base = '/vehicles/' . $golf->id . '/fuel';

        $html = self::body($browser->get($base));
        self::assertStringContainsString('2 fill-ups to check', $html);
        self::assertStringContainsString('href="' . $base . '?check=1"', $html);
        self::assertStringContainsString('Less than usual', $html);
        self::assertStringContainsString('More than usual', $html);
        self::assertMatchesRegularExpression(
            '/Used about 43% less than usual \([\d.]+ mpg; usually [\d.]+ mpg\)\./',
            $html,
        );
        $pair = 'Probably the fill-up on ' . $this->date($typo) . ': taken together, these two tanks are normal.';
        self::assertStringContainsString($pair, $html);
        self::assertStringContainsString('Edit the fill-up on ' . $this->date($opening), $html);

        // The figure is described by its flag, which carries its links and form.
        $document = Html::document($html);
        $economy = Html::element($document, 'span.economy[aria-describedby="check-' . $typo->id . '"]');
        self::assertNotSame('', trim($economy->textContent ?? ''));
        $flag = Html::element($document, '#check-' . $typo->id);
        self::assertStringContainsString('Looks right', $flag->textContent ?? '');
        $form = sprintf('#check-%d form[method="post"][action="%s/%d/economy"]', $typo->id, $base, $typo->id);
        Html::element($document, $form);
        Html::element($document, '#check-' . $after->id);

        // ?check=1 lists only the flagged fill-ups, their flags open.
        $html = self::body($browser->get($base . '?check=1'));
        $document = Html::document($html);
        self::assertStringContainsString('To check', $html);
        self::assertStringContainsString('Show all fill-ups', $html);
        self::assertCount(2, $document->querySelectorAll('a.list__item'));
        self::assertCount(2, $document->querySelectorAll('details.economy-check__details[open]'));

        // Normal tanks show no flag, and every average still counts both odd ones.
        self::assertSame(0, substr_count(self::body($browser->get($base)), 'id="check-' . $opening->id . '"'));
    }

    public function testNothingToCheckHasItsOwnEmptyState(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->usual($app, $golf, 6);

        $html = self::body($browser->get('/vehicles/' . $golf->id . '/fuel?check=1'));
        self::assertStringContainsString('Nothing to check', $html);
        self::assertStringNotContainsString('to check</a>', self::body($browser->get('/vehicles/' . $golf->id . '/fuel')));
    }

    public function testLooksRightConfirmsTheFigureUntilItChanges(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->usual($app, $golf, 5);
        $thirsty = $this->fill($app, $golf, 1000, '120');
        $base = '/vehicles/' . $golf->id . '/fuel';
        $statsBefore = self::stats(self::body($browser->get($base)));

        // Without CSRF nothing happens.
        $action = $base . '/' . $thirsty->id . '/economy';
        self::assertSame(400, $browser->post($action, [], [], false)->getStatusCode());
        self::assertNull($this->entry($app, $golf, $thirsty)->economyConfirmed);

        // Without JS: a plain form, back to the page it came from.
        $response = $browser->post($action, ['return' => $base . '?check=1']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame($base . '?check=1', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Marked as right.', self::body($browser->follow($response)));
        $stored = $this->entry($app, $golf, $thirsty)->economyConfirmed;
        self::assertSame('12.000000', $stored, 'the figure, computed by the server');

        $html = self::body($browser->get($base));
        self::assertStringNotContainsString('fill-ups to check', $html);
        self::assertStringNotContainsString('fill-up to check', $html);
        self::assertStringContainsString('Checked', $html);
        self::assertStringContainsString('Undo', $html);
        self::assertSame($statsBefore, self::stats($html), 'confirming changes no figure');

        // Undo, with JS (the desktop modal posts by fetch): the flag is back.
        $response = $browser->post($action, ['undo' => '1'], [], true, ['X-Logbook-Modal' => '1']);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame($base, $response->getHeaderLine('X-Logbook-Location'));
        self::assertNull($this->entry($app, $golf, $thirsty)->economyConfirmed);
        self::assertStringContainsString('1 fill-up to check', self::body($browser->get($base)));

        // Confirm again, then edit the volume: the owner confirmed a figure, not a fill-up.
        $browser->post($action);
        self::assertStringNotContainsString('fill-up to check', self::body($browser->get($base)));
        $entry = $this->entry($app, $golf, $thirsty);
        $this->service($app, FuelEntryRepository::class)->update(
            $golf->id,
            $entry->id,
            new FuelEntryData(
                $entry->data->filledAt,
                $entry->data->odometerKm,
                $entry->data->fuel,
                '125.000',
                $entry->data->pricePerUnit,
                $entry->data->totalCost,
            ),
            new DateTimeImmutable(self::NOW),
        );
        self::assertSame('12.000000', $this->entry($app, $golf, $thirsty)->economyConfirmed, 'editing keeps the stored figure');
        self::assertStringContainsString('1 fill-up to check', self::body($browser->get($base)));
    }

    public function testOnlyAFillUpClosingACheckableSegmentCanBeConfirmed(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        [$first] = $this->usual($app, $golf, 1);
        $short = $this->fill($app, $golf, 90, '20');

        foreach ([$first, $short] as $entry) {
            $response = $browser->post('/vehicles/' . $golf->id . '/fuel/' . $entry->id . '/economy');
            self::assertSame(404, $response->getStatusCode());
            self::assertNull($this->entry($app, $golf, $entry)->economyConfirmed);
        }
        self::assertSame(404, $browser->post('/vehicles/' . $golf->id . '/fuel/999999/economy')->getStatusCode());
    }

    public function testTheSaveNoticeAndTheEditPageShowTheFlag(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/fuel';
        $fill = ['fuel' => 'petrol', 'price' => '1.5', 'total' => '', 'station' => '', 'notes' => ''];

        // 500 mi on 40 L each time (56.8 mpg), then one on 60 L.
        for ($i = 0; $i < 7; $i++) {
            $browser->post($base . '/new', $fill + [
                'filled_at' => sprintf('2026-07-%02dT09:00', $i + 1),
                'odometer' => (string) (10000 + 500 * $i),
                'volume' => '40',
            ]);
        }
        $thirstyFill = ['filled_at' => '2026-07-08T09:00', 'odometer' => '13500', 'volume' => '60'];
        $response = $browser->post($base . '/new', $fill + $thirstyFill);
        self::assertSame(303, $response->getStatusCode(), 'flagged or not, the fill-up is saved');
        $html = self::body($browser->follow($response));
        self::assertStringContainsString('Fill-up saved: 37.9 mpg since the last full tank.', $html);
        self::assertStringContainsString('This tank used about 50% more than usual (37.9 mpg; usually 56.8 mpg).', $html);

        $entries = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id);
        self::assertCount(8, $entries);
        $thirsty = $entries[7];
        $edit = self::body($browser->get($base . '/' . $thirsty->id . '/edit'));
        self::assertStringContainsString('Used about 50% more than usual (37.9 mpg; usually 56.8 mpg).', $edit);
        self::assertStringContainsString('Check the odometer and the amount.', $edit);
        self::assertStringContainsString('Looks right', $edit);
        self::assertStringContainsString('Edit the fill-up on 7 Jul 2026', $edit);
        self::assertStringNotContainsString('Edit this fill-up', $edit, 'already on it');
        self::assertLessThan(
            strpos($edit, 'action="' . $base . '/' . $thirsty->id . '/edit"'),
            strpos($edit, 'Looks right'),
            'above the form, not inside it',
        );

        // An ordinary fill-up says nothing more.
        $ordinary = self::body($browser->get($base . '/' . $entries[3]->id . '/edit'));
        self::assertStringNotContainsString('than usual', $ordinary);
        $ordinaryFill = ['filled_at' => '2026-07-09T09:00', 'odometer' => '14000', 'volume' => '40'];
        $response = $browser->post($base . '/new', $fill + $ordinaryFill);
        self::assertStringNotContainsString('This tank used', self::body($browser->follow($response)));
    }

    public function testRecentFuelShowsTheFlag(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->usual($app, $golf, 5);
        $this->fill($app, $golf, 1000, '120');

        $html = self::body($browser->get('/'));
        self::assertStringContainsString('More than usual', $html);
        self::assertStringNotContainsString('Looks right', $html, 'the dashboard only marks it');

        // Nowhere else: not in history, print, reports or the garage.
        $pages = ['/history', '/vehicles/' . $golf->id . '/history', '/reports', '/vehicles', '/vehicles/' . $golf->id];
        foreach ($pages as $page) {
            self::assertStringNotContainsString('than usual', self::body($browser->get($page)), $page);
        }
    }

    public function testWithTheFuelModuleOffNothingAboutEconomyChecksAppears(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->usual($app, $golf, 5);
        $thirsty = $this->fill($app, $golf, 1000, '120');
        $this->service($app, FeatureToggles::class)->save([Feature::Maintenance, Feature::Compliance, Feature::Reminders]);

        self::assertStringNotContainsString('than usual', self::body($browser->get('/')));
        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/fuel?check=1')->getStatusCode());
        self::assertSame(404, $browser->post('/vehicles/' . $golf->id . '/fuel/' . $thirsty->id . '/economy')->getStatusCode());
        self::assertNull($this->entry($app, $golf, $thirsty)->economyConfirmed);
    }

    public function testFuelPagesWithFlagsWorkAtASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->usual($app, $golf, 5);
        $thirsty = $this->fill($app, $golf, 1000, '120');

        // Hard refresh of the deep link, the prefix stripped by the proxy.
        $html = self::body($browser->get('/vehicles/' . $golf->id . '/fuel?check=1'));
        self::assertStringContainsString('action="/logbook/vehicles/' . $golf->id . '/fuel/' . $thirsty->id . '/economy"', $html);
        self::assertStringContainsString('name="return" value="/logbook/vehicles/' . $golf->id . '/fuel?check=1"', $html);
    }

    // --- Helpers ----------------------------------------------------------

    /**
     * Five usual 1,300 km tanks at 8 L/100 km, then one with its odometer
     * typed 1,000 km too high (and the tank after it).
     *
     * @param App<ContainerInterface> $app
     * @return array{FuelEntry, FuelEntry, FuelEntry} the typo's opening fill-up, the typo, the next
     */
    private function pair(App $app, Vehicle $vehicle): array
    {
        $this->usual($app, $vehicle, 5, 1300, '104');
        $opening = $this->fill($app, $vehicle, 1300, '104');
        $typo = $this->fill($app, $vehicle, 2300, '104');

        return [$opening, $typo, $this->fill($app, $vehicle, 300, '104')];
    }

    /**
     * A starting fill-up and $segments ordinary tanks.
     *
     * @param App<ContainerInterface> $app
     * @return list<FuelEntry>
     */
    private function usual(App $app, Vehicle $vehicle, int $segments, int $distance = 1000, string $litres = '80'): array
    {
        $fills = [$this->fill($app, $vehicle, 0, $litres)];
        for ($i = 0; $i < $segments; $i++) {
            $fills[] = $this->fill($app, $vehicle, $distance, $litres);
        }

        return $fills;
    }

    /**
     * A full fill-up $distance km after the last one, a day later.
     *
     * @param App<ContainerInterface> $app
     */
    private function fill(App $app, Vehicle $vehicle, int $distance, string $litres): FuelEntry
    {
        $this->km += $distance;
        $this->day++;
        $at = (new DateTimeImmutable('2026-01-01T08:00:00Z'))->modify(sprintf('+%d days', $this->day));

        $utc = $at->format('Y-m-d\TH:i:s\Z');

        return $this->fillUp($app, $vehicle, $utc, (string) $this->km, $litres, '0', pricePerLitre: '1.5');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function entry(App $app, Vehicle $vehicle, FuelEntry $entry): FuelEntry
    {
        $found = $this->service($app, FuelEntryRepository::class)->find($vehicle->id, $entry->id);
        self::assertNotNull($found);

        return $found;
    }

    private function date(FuelEntry $entry): string
    {
        return $entry->data->filledAt->setTimezone(new DateTimeZone('Europe/London'))->format('j M Y');
    }

    /**
     * The Fuel tab's summary figures.
     */
    private static function stats(string $html): string
    {
        return Html::element(Html::document($html), 'dl.stats')->textContent ?? '';
    }
}
