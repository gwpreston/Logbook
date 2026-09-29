<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition as P;
use Logbook\Domain\Tyre\TyreRetireReason;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\History\ActivityFeed;
use Logbook\Service\History\ActivityKind;
use Logbook\Service\History\ActivityQuery;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreCost;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Tyre changes in History, the print view, Recent activity and the
 * overview (spec.md §7.16, §7.17): a linked change is never listed on its
 * own; its service record's row carries it under both chips.
 */
final class TyreHistoryTest extends AppTestCase
{
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->browser = $this->signedIn($this->app);
        $this->car = $this->vehicle($this->app);

        $changes = $this->service($this->app, TyreChangeService::class);
        $zone = new DateTimeZone('Europe/London');
        $changes->existing(
            $this->car,
            new TyreChangeData(self::day('2026-01-10'), '20000.000'),
            self::tyres([P::FrontLeft, P::FrontRight, P::RearLeft, P::RearRight], 'Energy Saver'),
            $zone,
            'en_GB',
        );
        $changes->fit(
            $this->car,
            new TyreChangeData(self::day('2026-06-15'), '28000.000'),
            self::tyres([P::FrontLeft, P::FrontRight], 'Primacy 4'),
            ['fl' => TyreRetireReason::Worn, 'fr' => TyreRetireReason::Worn],
            new TyreCost('240.000', 'Kwik Fit'),
            $zone,
            'en_GB',
        );
    }

    /**
     * @param list<P> $positions
     * @return list<NewTyre>
     */
    private static function tyres(array $positions, string $model): array
    {
        $data = new TyreData('Michelin', $model, '205/55 R16 91V');

        return array_map(static fn (P $p): NewTyre => new NewTyre($p, $data), $positions);
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }

    private function history(string $query = ''): string
    {
        return self::body($this->browser->get('/vehicles/' . $this->car->id . '/history' . $query));
    }

    public function testALinkedChangeIsListedOnceOnItsServiceRecordsRow(): void
    {
        $html = $this->history();

        self::assertSame(1, substr_count($html, 'Fitted 2 × Michelin Primacy 4 (front)'), 'listed once, as the second line');
        self::assertStringContainsString('history-row__tyres', $html);
        self::assertStringContainsString('2 × Michelin Primacy 4, front', $html, 'the service record row');
        self::assertStringContainsString('Recorded 4 tyres (all four)', $html, 'an unlinked change is its own row');
        self::assertStringContainsString('Tyres recorded', $html);
        self::assertStringContainsString('?kind=tyres', $html, 'the Tyres chip');

        $service = $this->history('?kind=service');
        self::assertStringContainsString('Fitted 2 × Michelin Primacy 4 (front)', $service, 'the row counts under Service');
        self::assertStringNotContainsString('Recorded 4 tyres', $service);

        $tyres = $this->history('?kind=tyres');
        self::assertSame(1, substr_count($tyres, 'Fitted 2 × Michelin Primacy 4 (front)'), 'and under Tyres');
        self::assertStringContainsString('Recorded 4 tyres (all four)', $tyres);
        self::assertStringContainsString('£240.00', $tyres, 'its cost comes from the service record');
    }

    public function testTheFeedCountsTheCostOnce(): void
    {
        $items = $this->service($this->app, ActivityFeed::class)->items(
            $this->owner($this->app),
            new ActivityQuery([$this->car], ActivityKind::entries()),
        );

        $amounts = array_values(array_filter(array_map(static fn ($i): ?string => $i->amount, $items)));
        self::assertSame(['240.000'], $amounts);
        self::assertSame(
            ['maintenance', 'tyre'],
            array_map(static fn ($i): string => $i->kind->value, $items),
            'the fit is on its record; the first change is its own row; its reading is left out',
        );
    }

    public function testWithMaintenanceOffALinkedChangeIsListedOnItsOwn(): void
    {
        $this->service($this->app, FeatureToggles::class)
            ->save([Feature::Fuel, Feature::Compliance, Feature::Reminders, Feature::Reports, Feature::Tyres]);

        $html = $this->history();
        self::assertStringContainsString('Tyres fitted', $html);
        self::assertStringContainsString('Fitted 2 × Michelin Primacy 4 (front)', $html);
        self::assertStringNotContainsString('£240.00', $html, 'no cost without the service record');
    }

    public function testWithTyresOffTheChipRowsAndSecondLineGo(): void
    {
        $this->service($this->app, FeatureToggles::class)
            ->save([Feature::Fuel, Feature::Maintenance, Feature::Compliance, Feature::Reminders, Feature::Reports]);

        $html = $this->history();
        self::assertStringNotContainsString('?kind=tyres', $html);
        self::assertStringNotContainsString('Recorded 4 tyres', $html);
        self::assertStringNotContainsString('history-row__tyres', $html);
        self::assertStringContainsString('2 × Michelin Primacy 4, front', $html, 'the service record is untouched');
        self::assertStringNotContainsString('Recorded 4 tyres', $this->history('?kind=tyres'), 'falls back to Everything');
    }

    public function testThePrintHeaderShowsTheFittedTyresAndTyresArePrintedByDefault(): void
    {
        $html = self::body($this->browser->get('/vehicles/' . $this->car->id . '/history/print'));

        self::assertStringContainsString('Tyres fitted', $html);
        self::assertStringContainsString('Front left: Michelin Primacy 4, 205/55 R16 91V', $html);
        self::assertStringContainsString('Rear right: Michelin Energy Saver', $html);
        self::assertStringContainsString('name="kinds[]" value="tyres" checked', $html);
        self::assertStringContainsString('Recorded 4 tyres (all four)', $html);
    }

    public function testRecentActivityAndTheOverviewCard(): void
    {
        $dashboard = self::body($this->browser->get('/'));
        self::assertStringContainsString('Tyres recorded', $dashboard);
        self::assertStringContainsString('/tyres/changes/', $dashboard, 'links to the change');

        $overview = self::body($this->browser->get('/vehicles/' . $this->car->id));
        self::assertStringContainsString('All tyres →', $overview);
        self::assertStringContainsString('Front left · Michelin Primacy 4', $overview);
    }

    public function testTheOverviewCardIsHiddenWithoutTyres(): void
    {
        $other = $this->vehicle($this->app, 'Ford', 'Focus');

        self::assertStringNotContainsString('All tyres →', self::body($this->browser->get('/vehicles/' . $other->id)));
    }
}
