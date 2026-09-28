<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\History;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\History\ActivityFeed;
use Logbook\Service\History\ActivityItem;
use Logbook\Service\History\ActivityKind;
use Logbook\Service\History\ActivityQuery;
use Logbook\Service\History\HistoryChip;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The shared activity feed (spec.md §7.16) against a real database: order,
 * year pages in the owner's zone, what is left out, milestones and the
 * neighbouring years.
 */
final class ActivityFeedTest extends AppTestCase
{
    use CostFixtures;

    public function testNewestFirstByLocalDateThenByWhenAdded(): void
    {
        [$app, $owner, $golf] = $this->setUpOwner();
        $clock = $this->pinClock($app, '2026-09-20T08:00:00Z');
        $this->expense($app, $golf, '2026-09-10', '3.00', note: 'added first');
        $clock->set(new DateTimeImmutable('2026-09-20T09:00:00Z'));
        $this->maintenance($app, $golf, '2026-09-10', 'added second', '0');
        $clock->set(new DateTimeImmutable('2026-09-20T10:00:00Z'));
        $this->fillUp($app, $golf, '2026-09-09T20:00:00Z', '1000', '40', '60');
        $this->reading($app, $golf, '1200', '2026-09-12T08:00:00Z');

        self::assertSame(
            ['odometer 2026-09-12', 'maintenance 2026-09-10', 'expense 2026-09-10', 'fuel 2026-09-09'],
            self::lines($this->feed($app)->items($owner, new ActivityQuery([$golf], ActivityKind::entries()))),
        );
    }

    public function testAYearPageIsBoundedInTheOwnersTimeZone(): void
    {
        [$app, $owner, $golf] = $this->setUpOwner('Europe/Berlin');
        // 00:30 on 1 January in Berlin is 23:30 UTC on 31 December.
        $this->fillUp($app, $golf, '2025-12-31T23:30:00Z', '1000', '40', '60');
        $this->fillUp($app, $golf, '2025-12-31T22:30:00Z', '900', '40', '60');

        $feed = $this->feed($app);
        $new = $feed->year($owner, [$golf], HistoryChip::Everything->kinds(), 2026);
        self::assertSame(['fuel 2026-01-01'], self::lines($new->items), 'on the new year\'s page');
        self::assertSame(2025, $new->older);
        $old = $feed->year($owner, [$golf], HistoryChip::Everything->kinds(), 2025);
        self::assertSame(['fuel 2025-12-31'], self::lines($old->items));
        self::assertSame(2026, $old->newer);
    }

    public function testDocumentsWithoutAStartAreOnTheDayAdded(): void
    {
        [$app, $owner, $golf] = $this->setUpOwner();
        $this->pinClock($app, '2026-03-31T23:30:00Z'); // 00:30 BST on 1 April
        $this->document($app, $golf, ComplianceType::Other, null, null, '0', 'Breakdown cover');
        $this->document($app, $golf, ComplianceType::Insurance, '2026-02-01', '2027-01-31', '420');

        self::assertSame(
            ['document 2026-04-01', 'document 2026-02-01'],
            self::lines($this->feed($app)->items($owner, new ActivityQuery([$golf], ActivityKind::entries()))),
        );
    }

    public function testDerivedReadingsAndSwitchedOffModulesAreLeftOut(): void
    {
        [$app, $owner, $golf] = $this->setUpOwner();
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '1000', '40', '60');
        $this->maintenance($app, $golf, '2026-09-02', 'Service', '100', '1100');
        $this->reading($app, $golf, '1200', '2026-09-03T08:00:00Z');
        $this->expense($app, $golf, '2026-09-04', '2');
        $all = new ActivityQuery([$golf], ActivityKind::entries());

        self::assertSame(
            ['expense 2026-09-04', 'odometer 2026-09-03', 'maintenance 2026-09-02', 'fuel 2026-09-01'],
            self::lines($this->feed($app)->items($owner, $all)),
            'the fill-up\'s and the service\'s readings are theirs',
        );

        $this->service($app, FeatureToggles::class)->save([Feature::Compliance, Feature::Reminders, Feature::Reports]);
        self::assertSame(['expense 2026-09-04', 'odometer 2026-09-03'], self::lines($this->feed($app)->items($owner, $all)));
        $year = $this->feed($app)->year($owner, [$golf], [ActivityKind::Fuel], null);
        self::assertTrue($year->isEmpty(), 'fuel is off');
    }

    public function testMilestonesComeFromTheVehicleWithTheirPricesApart(): void
    {
        [$app, $owner, $golf] = $this->setUpOwner();
        $golf = $this->service($app, VehicleService::class)->update($owner, $golf, new VehicleData(
            $golf->data->type,
            $golf->data->make,
            $golf->data->model,
            $golf->data->fuelType,
            purchaseDate: LocalTime::parseDate('2021-05-01'),
            purchasePrice: '12500.00',
            firstRegisteredOn: LocalTime::parseDate('2019-03-14'),
        ));
        $this->expense($app, $golf, '2021-05-01', '5');

        $items = $this->feed($app)->items($owner, new ActivityQuery([$golf], HistoryChip::Everything->kinds()));
        self::assertSame(['expense 2021-05-01', 'milestone 2021-05-01', 'milestone 2019-03-14'], self::lines($items));
        self::assertSame('12500.000', $items[1]->price);
        self::assertNull($items[1]->amount, 'the purchase is not a cost');
        self::assertSame('history.milestone.first_registered', $items[2]->labelKey);
        self::assertSame([], array_filter(
            $this->feed($app)->latest($owner, [$golf]),
            static fn (ActivityItem $i): bool => $i->kind === ActivityKind::Milestone,
        ), 'the widget lists entries only');
    }

    public function testYearsSkipEmptyOnesAndOutOfRangeFallsBack(): void
    {
        [$app, $owner, $golf] = $this->setUpOwner();
        $this->expense($app, $golf, '2019-06-01', '1');
        $this->maintenance($app, $golf, '2021-06-01', 'Service', '1');
        $this->reading($app, $golf, '5000', '2024-06-01T08:00:00Z');
        $feed = $this->feed($app);
        $everything = HistoryChip::Everything->kinds();

        $default = $feed->year($owner, [$golf], $everything, null);
        self::assertSame(
            [2024, 2019, 2024, null, 2021],
            [$default->year, $default->first, $default->newest, $default->newer, $default->older],
        );
        $middle = $feed->year($owner, [$golf], $everything, 2021);
        self::assertSame([2024, 2019], [$middle->newer, $middle->older], 'empty years are skipped');
        $empty = $feed->year($owner, [$golf], $everything, 2022);
        self::assertSame(2022, $empty->year, 'an empty year in range has its own page');
        self::assertSame([], $empty->items);
        self::assertSame(2024, $feed->year($owner, [$golf], $everything, 2030)->year, 'out of range: the default');
        self::assertSame(2024, $feed->year($owner, [$golf], $everything, 1990)->year);

        $service = $feed->year($owner, [$golf], HistoryChip::Service->kinds(), null);
        self::assertSame([2021, 2021, null, null], [$service->year, $service->first, $service->newer, $service->older]);
    }

    public function testTheLatestWalkBackAcrossYears(): void
    {
        [$app, $owner, $golf] = $this->setUpOwner();
        foreach (['2023-01-05', '2023-11-01', '2024-02-01', '2026-01-10', '2026-03-10'] as $i => $day) {
            $this->expense($app, $golf, $day, (string) ($i + 1));
        }
        $latest = $this->feed($app)->latest($owner, [$golf], 4);
        self::assertSame(
            ['expense 2026-03-10', 'expense 2026-01-10', 'expense 2024-02-01', 'expense 2023-11-01'],
            self::lines($latest),
        );
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: User, 2: Vehicle}
     */
    private function setUpOwner(string $zone = 'Europe/London'): array
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $preset = UnitPreset::Uk;
        $owner = $this->createOwner($app, preferences: new DisplayPreferences(
            'en_GB',
            $zone,
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        ));

        return [$app, $owner, $this->vehicle($app)];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function feed(App $app): ActivityFeed
    {
        return $this->service($app, ActivityFeed::class);
    }

    /**
     * @param list<ActivityItem> $items
     * @return list<string>
     */
    private static function lines(array $items): array
    {
        return array_map(static fn (ActivityItem $i): string => $i->kind->value . ' ' . $i->date->format('Y-m-d'), $items);
    }
}
