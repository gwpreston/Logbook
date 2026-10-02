<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\PlaceData;
use Logbook\Domain\Station\StationData;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\PlaceRepository;
use Logbook\Repository\StationRepository;
use Logbook\Tests\Support\JsonDoc;

/**
 * `stations(query?, favourites_only?)` (spec.md §7.26, §7.33): "Where do I
 * usually fill up?" and "What's the cheapest I've paid at Tesco?", from the
 * user's own fill-ups; never their places.
 */
final class StationsToolTest extends ToolsBTestCase
{
    public function testWhereDoIUsuallyFillUpAndTheCheapestPaid(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app);
        $now = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $stations = $this->service($app, StationRepository::class);
        $tesco = $stations->insert(new StationData('Tesco Antrim', 'Tesco'), $owner->id, $now);
        $maxol = $stations->insert(new StationData('Maxol Antrim'), $owner->id, $now);
        $stations->setFavourite($owner->id, $maxol, true, $now);
        $this->service($app, PlaceRepository::class)->insert($owner->id, new PlaceData('Home', '54.7', '-6.2'), $now);
        $this->fillUp($app, $golf, '2026-07-01T08:00:00Z', '1000', '40', '56.00', grade: FuelGrade::E10_95);
        $this->fillUp($app, $golf, '2026-07-15T08:00:00Z', '1500', '40', '54.00', grade: FuelGrade::E10_95);
        $this->fillUp($app, $golf, '2026-08-01T08:00:00Z', '2000', '40', '58.00', grade: FuelGrade::E10_95);
        $entries = $this->service($app, FuelEntryRepository::class);
        foreach ($entries->listForVehicle($golf->id) as $index => $entry) {
            $at = $index < 2 ? $tesco : $maxol;
            $name = $index < 2 ? 'Tesco Antrim' : 'Maxol Antrim';
            $entries->update($golf->id, $entry->id, $entry->data->withStation($at, $name), $now);
        }
        $this->assertSchemaAccepts($app, $owner, 'stations', ['query' => 'Tesco', 'favourites_only' => false]);

        $usual = $this->toolResult($app, $owner, 'stations');
        $data = new JsonDoc($usual->data);
        self::assertSame(['Tesco Antrim', 'Maxol Antrim'], $data->column('name', 'stations'), 'most visited first');
        self::assertSame(2, $data->int('stations', 0, 'visits'));
        self::assertSame('£1.350/L', $data->get('stations', 0, 'paid', 0, 'cheapest_price', 'display'));
        self::assertSame('15 Jul 2026', $data->get('stations', 0, 'paid', 0, 'cheapest_price', 'on'));
        self::assertSame('£1.375/L', $data->get('stations', 0, 'paid', 0, 'average_price', 'display'));
        self::assertStringNotContainsString('Home', json_encode($usual->data, JSON_THROW_ON_ERROR), 'never the places');
        self::assertSame('/stations', $usual->link);

        $tescoOnly = new JsonDoc($this->toolResult($app, $owner, 'stations', ['query' => 'tesco'])->data);
        self::assertSame(['Tesco Antrim'], $tescoOnly->column('name', 'stations'));
        $favourites = new JsonDoc($this->toolResult($app, $owner, 'stations', ['favourites_only' => true])->data);
        self::assertSame(['Maxol Antrim'], $favourites->column('name', 'stations'));
    }
}
