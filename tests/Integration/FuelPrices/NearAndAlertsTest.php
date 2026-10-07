<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\StationLink;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Repository\PriceAlertRepository;
use Logbook\Repository\StationRepository;
use Logbook\Service\FuelPrices\CheapestNear;
use Logbook\Service\FuelPrices\LinkRefused;
use Logbook\Service\FuelPrices\NearOrigin;
use Logbook\Service\FuelPrices\NearRow;
use Logbook\Service\FuelPrices\PriceAlertRefused;
use Logbook\Service\FuelPrices\PriceAlerts;
use Logbook\Service\FuelPrices\StationLinker;
use Logbook\Service\FuelPrices\Uk\FuelFinderProvider;
use Logbook\Service\FuelPrices\VehicleFuelProfiles;
use Logbook\Service\Notification\ChannelRegistry;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\QuietHours;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Station\StationService;
use Logbook\Tests\Support\FakeChannel;
use DI\Container;
use Logbook\Support\Number\Decimal;

/**
 * Cheapest near me, the vehicle's usual fill and economy, linking and
 * price alerts (spec.md §7.34) on the synced fixture.
 */
final class NearAndAlertsTest extends FuelPricesTestCase
{
    private const float HOME_LAT = 54.716;
    private const float HOME_LON = -6.208;

    public function testStationsAreRankedByEffectiveCostWithoutClosedOrStaleOnes(): void
    {
        [$app] = $this->pricesApp();
        $this->sync($app);
        $golf = $this->vehicle($app);

        $result = $this->service($app, CheapestNear::class)
            ->search(NearOrigin::here(self::HOME_LAT, self::HOME_LON), $golf, FuelGrade::E10_95, 8.0);

        self::assertNotNull($result);
        self::assertSame(
            ['Tesco Antrim Extra', 'Shell Junction One'],
            array_map(static fn (NearRow $row): string => $row->providerStation->data->name, $result->rows),
            'Maxol is temporarily closed, Larne Road closed for good, the M2 services have no position',
        );
        self::assertTrue($result->rows[0]->isNearest());
        self::assertSame('40', $result->profile->usualFill, 'no fill-ups: 40 L assumed');
        self::assertTrue($result->profile->fillAssumed);
        self::assertFalse($result->profile->hasEconomy(), 'no economy: the drive is not counted');
        self::assertSame('54.3600', $result->rows[0]->cost->total, '40 L at £1.359');
        $shell = $result->rows[1]->worthIt;
        self::assertNotNull($shell);
        self::assertSame('-0.8000', $shell->actualSaving, '2p a litre more over 40 L');
        self::assertNotNull($result->lastSync);

        // A mile away from everything: nothing within the radius.
        $none = $this->service($app, CheapestNear::class)->search(NearOrigin::here(54.0, -6.0), $golf, FuelGrade::E10_95, 2.0);
        self::assertSame([], $none?->rows);
    }

    public function testOlderPricesAreLeftOutUnlessAskedFor(): void
    {
        [$app] = $this->pricesApp();
        $this->sync($app);
        $golf = $this->vehicle($app);
        $near = $this->service($app, CheapestNear::class);
        $here = NearOrigin::here(self::HOME_LAT, self::HOME_LON);

        $this->clock->set(new DateTimeImmutable('2026-10-05T07:00:00Z'));
        self::assertSame([], $near->search($here, $golf, FuelGrade::E10_95, 8.0)?->rows, 'over 48 hours old');
        $older = $near->search($here, $golf, FuelGrade::E10_95, 8.0, includeOlder: true);
        self::assertNotNull($older);
        self::assertCount(2, $older->rows);
        self::assertFalse($older->rows[0]->fresh);
    }

    public function testTheUsualFillAndEconomyComeFromTheLiquidFills(): void
    {
        [$app] = $this->pricesApp();
        $phev = $this->vehicle($app, 'Mitsubishi', 'Outlander', fuel: FuelType::Phev);
        // Full tanks of 30, 34, 40 and 38 L 600 km apart (a partial between), and charging in between.
        $this->fillUp($app, $phev, '2026-06-01T08:00:00Z', '10000', '30', '42.00', grade: FuelGrade::E10_95);
        $this->fillUp($app, $phev, '2026-06-20T08:00:00Z', '10300', '10', '14.00', partial: true, grade: FuelGrade::E10_95);
        $this->fillUp($app, $phev, '2026-07-01T08:00:00Z', '10600', '34', '47.60', grade: FuelGrade::E10_95);
        $this->fillUp($app, $phev, '2026-07-15T08:00:00Z', '10900', '20', '6.00', grade: FuelGrade::Home);
        $this->fillUp($app, $phev, '2026-08-01T08:00:00Z', '11200', '40', '56.00', grade: FuelGrade::E10_95);
        $this->fillUp($app, $phev, '2026-09-01T08:00:00Z', '11800', '38', '53.20', grade: FuelGrade::E10_95);

        $profile = $this->service($app, VehicleFuelProfiles::class)->for($phev, new DateTimeImmutable(self::NOW));

        self::assertSame('36.00', $profile->usualFill, 'the median of 30, 34, 40 and 38 L; the partial and the charge left out');
        self::assertFalse($profile->fillAssumed);
        self::assertTrue($profile->hasEconomy());
        self::assertSame('1800.000', Decimal::round((string) $profile->economyKm, 3), 'three full-to-full segments of liquid');
        self::assertSame(FuelGrade::E10_95, $profile->grade);
        // 122 L over 1,800 km.
        self::assertSame('6.7778', Decimal::round(Decimal::divide((string) $profile->economyLitres, '18', 6), 4));
    }

    public function testLinkingOffersNearbyStationsBestNameFirst(): void
    {
        [$app, $owner] = $this->pricesApp();
        $this->sync($app);
        $linker = $this->service($app, StationLinker::class);

        // 20 m from Tesco, 1.5 km from everything else.
        $mine = $this->station($app, 'Tesco Antrim', '54.718100', '-6.219200');
        $candidates = $linker->candidates($mine);
        self::assertSame([self::ref('antrim-tesco')], array_map(static fn ($c): string => $c->station->ref(), $candidates));
        self::assertLessThan(0.15, (float) $candidates[0]->km);

        // Without a position: the postcode, then the name.
        $noPosition = $this->station($app, 'Shell', postcode: 'bt41 1aa');
        self::assertSame(self::ref('antrim-shell'), $linker->candidates($noPosition)[0]->station->ref());

        $linked = $linker->link($mine, self::ref('antrim-tesco'));
        self::assertEquals(new StationLink(FuelFinderProvider::CODE, self::ref('antrim-tesco')), $linked->link);
        self::assertSame('BT41 4LD', $linked->data->postcode, 'details copied on linking');

        // One Logbook station per provider station.
        $other = $this->station($app, 'Tesco Extra', '54.718000', '-6.219000');
        self::assertSame([], $linker->candidates($other), 'Tesco is taken');
        try {
            $linker->link($other, self::ref('antrim-tesco'));
            self::fail('a provider station links one station only');
        } catch (LinkRefused $e) {
            self::assertSame('taken', $e->reason);
        }

        // Merging keeps the link: the kept one takes it when it has none.
        $stations = $this->service($app, StationService::class);
        $merged = $stations->merge($other, $linked, StationService::mergedData($other, $linked));
        self::assertSame(self::ref('antrim-tesco'), $merged->link?->ref);
        self::assertNull($this->service($app, StationRepository::class)->find($linked->id)?->link);

        // *Add station* from a result: a new station, linked, or the same one again.
        $added = $linker->addFromProvider($owner, self::ref('antrim-shell'));
        self::assertSame('Shell Junction One', $added->data->name);
        self::assertSame(self::ref('antrim-shell'), $added->link?->ref);
        self::assertSame($added->id, $linker->addFromProvider($owner, self::ref('antrim-shell'))->id);
    }

    public function testAnAlertIsSentOncePerDropAndRearmedWhenThePriceGoesBackUp(): void
    {
        [$app, $owner] = $this->pricesApp();
        $channel = new FakeChannel('fake');
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(ChannelRegistry::class, new ChannelRegistry([$channel]));
        $this->sync($app);
        $tesco = $this->service($app, StationLinker::class)->addFromProvider($owner, self::ref('antrim-tesco'));
        $alerts = $this->service($app, PriceAlerts::class);

        try {
            $alerts->set($owner, $tesco, FuelGrade::E10_95, '1.369');
            self::fail('only on favourites');
        } catch (PriceAlertRefused $e) {
            self::assertSame('not_favourite', $e->reason);
        }
        $this->service($app, StationService::class)->setFavourite($owner, $tesco, true);
        $alerts->set($owner, $tesco, FuelGrade::E10_95, '1.369');

        // Listed at 1.359: below 1.369, sent once.
        $this->sync($app);
        self::assertCount(1, $channel->sent);
        self::assertSame('E10 95 at Tesco Antrim Extra: £1.359/L', $channel->sent[0]->title);
        self::assertStringContainsString('below your alert of £1.369/L', $channel->sent[0]->message);
        $this->sync($app);
        self::assertCount(1, $channel->sent, 'never twice for one drop');

        // Back up to 1.379: re-armed; down to 1.349: sent again.
        $this->clock->set(new DateTimeImmutable('2026-10-03T09:00:00Z'));
        $this->prices = [[
            'node_id' => self::ref('antrim-tesco'),
            'fuel_prices' => [['price' => '0137.9000', 'fuel_type' => 'E10', 'price_last_updated' => '2026-10-03T08:30:00']],
        ]];
        $this->sync($app);
        self::assertNull($this->service($app, PriceAlertRepository::class)->forUser($owner->id)[0]->triggeredAt);
        $this->clock->set(new DateTimeImmutable('2026-10-03T10:00:00Z'));
        $this->prices = [[
            'node_id' => self::ref('antrim-tesco'),
            'fuel_prices' => [['price' => '0134.9000', 'fuel_type' => 'E10', 'price_last_updated' => '2026-10-03T09:30:00']],
        ]];
        $this->sync($app);
        self::assertCount(2, $channel->sent);

        // Removing the favourite removes the alert.
        $this->service($app, StationService::class)->setFavourite($owner, $tesco, false);
        self::assertSame([], $this->service($app, PriceAlertRepository::class)->forUser($owner->id));
    }

    /**
     * Phase 36.4 (spec.md §7.11): inside the user's quiet hours an alert
     * stays armed; the first check after sends what still applies, every
     * alert of the user's in one message (#253).
     */
    public function testAlertsWaitForQuietHoursAndGoTogether(): void
    {
        [$app, $owner] = $this->pricesApp();
        $channel = new FakeChannel('fake');
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(ChannelRegistry::class, new ChannelRegistry([$channel]));
        $this->sync($app);
        $linker = $this->service($app, StationLinker::class);
        $stations = $this->service($app, StationService::class);
        $alerts = $this->service($app, PriceAlerts::class);
        foreach (['antrim-tesco' => '1.369', 'antrim-shell' => '1.389'] as $ref => $below) {
            $station = $linker->addFromProvider($owner, self::ref($ref));
            $stations->setFavourite($owner, $station, true);
            $alerts->set($owner, $station, FuelGrade::E10_95, $below);
        }
        // 06:00 to 09:00 holds 07:00 UTC whether the owner is on UTC or British time.
        $store = $this->service($app, ReminderSettingsStore::class);
        $store->saveNotificationPreferences($owner->id, $store->notificationPreferences($owner->id)
            ->withQuiet(QuietHours::of('06:00', '09:00')));

        $this->sync($app);
        self::assertSame([], $channel->sent, 'held');
        foreach ($this->service($app, PriceAlertRepository::class)->forUser($owner->id) as $alert) {
            self::assertNull($alert->triggeredAt, 'still armed');
        }

        $this->clock->set(new DateTimeImmutable('2026-10-03T10:00:00Z'));
        $this->sync($app);
        self::assertCount(1, $channel->sent, 'one message');
        $sent = self::firstSent($channel);
        self::assertSame('2 price alerts', $sent->title);
        self::assertStringContainsString('E10 95 at Tesco Antrim Extra: £1.359/L', $sent->message);
        self::assertStringContainsString('Shell Junction One', $sent->message);
        $this->sync($app);
        self::assertCount(1, $channel->sent, 'once');
    }

    public function testAlertsIgnoreStalePricesAndClosedStationsAndHaveALimit(): void
    {
        [$app, $owner] = $this->pricesApp();
        $channel = new FakeChannel('fake');
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(ChannelRegistry::class, new ChannelRegistry([$channel]));
        $this->sync($app);
        $linker = $this->service($app, StationLinker::class);
        $stations = $this->service($app, StationService::class);
        $alerts = $this->service($app, PriceAlerts::class);

        // Maxol is temporarily closed and its E10 price is days old.
        $maxol = $linker->addFromProvider($owner, self::ref('antrim-maxol'));
        $stations->setFavourite($owner, $maxol, true);
        $alerts->set($owner, $maxol, FuelGrade::B7, '1.999');
        $alerts->set($owner, $maxol, FuelGrade::E10_95, '1.999');
        $this->sync($app);
        self::assertSame([], $channel->sent);

        try {
            $alerts->set($owner, $maxol, FuelGrade::E5_97, '1.500');
            self::fail('Maxol does not list E5');
        } catch (PriceAlertRefused $e) {
            self::assertSame('grade', $e->reason);
        }
        try {
            $alerts->set($owner, $maxol, FuelGrade::B7, '0');
            self::fail('a price is needed');
        } catch (PriceAlertRefused $e) {
            self::assertSame('price', $e->reason);
        }

        $repository = $this->service($app, PriceAlertRepository::class);
        $now = new DateTimeImmutable(self::NOW);
        $grades = [FuelGrade::E10_95, FuelGrade::E5_97, FuelGrade::B7];
        for ($i = 0; count($repository->forUser($owner->id)) < PriceAlerts::MAX_PER_USER; $i++) {
            $id = $this->service($app, StationRepository::class)->insert(new StationData('Filler ' . $i), $owner->id, $now);
            $repository->save($owner->id, $id, $grades[$i % 3], '1.000', $now);
        }
        $tesco = $linker->addFromProvider($owner, self::ref('antrim-tesco'));
        $stations->setFavourite($owner, $tesco, true);
        try {
            $alerts->set($owner, $tesco, FuelGrade::E10_95, '1.300');
            self::fail('20 alerts at most');
        } catch (PriceAlertRefused $e) {
            self::assertSame('limit', $e->reason);
        }
        $alerts->set($owner, $maxol, FuelGrade::B7, '1.400');
        self::assertSame('1.400', $repository->forStation($owner->id, $maxol->id)[0]->below, 'changing one is not a new one');
    }

    private static function firstSent(FakeChannel $channel): Notification
    {
        return $channel->sent[0] ?? self::fail('nothing sent');
    }
}
