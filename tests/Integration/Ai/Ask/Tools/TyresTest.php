<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition as P;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\JsonDoc;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * `tyres` (spec.md §7.26): fitted and stored tyres with tread and wear.
 */
final class TyresTest extends ToolsBTestCase
{
    public function testFittedTyresWithTreadAndTheServicesFigures(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app);
        $this->tyred($app, $golf);
        $this->assertSchemaAccepts($app, $owner, 'tyres', ['vehicle' => $golf->id]);

        $result = $this->toolResult($app, $owner, 'tyres', ['vehicle' => $golf->id]);
        $data = new JsonDoc($result->data);
        $overview = $this->service($app, TyreService::class)->overview($golf, $owner);

        self::assertSame(['fl', 'fr', 'rl', 'rr'], $data->column('position', 'fitted'));
        $front = $data->doc('fitted', 0);
        self::assertSame('Front left', $front->get('position_label'));
        self::assertSame('Michelin', $front->get('brand'));
        self::assertSame('5.000', $front->get('tread', 'mm'));
        self::assertSame($overview->fitted['fl']?->distance->km, $front->get('distance', 'km'));
        self::assertSame('6,214 mi', $front->get('distance', 'display'), '10,000 km in miles');
        self::assertSame($overview->verdict->status->value, $data->get('verdict', 'status'));
        self::assertSame('/vehicles/' . $golf->id . '/tyres', $result->link);
        self::assertSame('Tyres · Volkswagen Golf', $result->source);
    }

    public function testDistancesInKilometres(): void
    {
        $preset = UnitPreset::Metric;
        [$app, $owner] = $this->askApp([], new DisplayPreferences(
            'en_GB',
            'Europe/London',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        ));
        $golf = $this->vehicle($app);
        $this->tyred($app, $golf);

        $data = $this->data($app, $owner, 'tyres', ['vehicle' => $golf->id]);

        self::assertSame('10,000 km', $data->get('fitted', 0, 'distance', 'display'));
    }

    public function testAnotherUsersVehicleIsNotFoundAndTheModuleOffHidesTheTool(): void
    {
        [$app] = $this->askApp();
        $golf = $this->vehicle($app);
        $partner = $this->createMember($app);
        self::assertSame(ToolKit::NOT_FOUND, $this->call($app, $partner, 'tyres', ['vehicle' => $golf->id])->error);

        [$off, $owner] = $this->askApp(['FEATURES_TYRES' => 'false']);
        self::assertFalse($this->isOffered($off, $owner, 'tyres'));
    }

    /**
     * Four Michelins fitted at 20,000 km, checked at 5 mm 10,000 km later.
     *
     * @param App<ContainerInterface> $app
     */
    private function tyred(App $app, Vehicle $vehicle): void
    {
        $changes = $this->service($app, TyreChangeService::class);
        $zone = new DateTimeZone('Europe/London');
        $data = new TyreData('Michelin', 'Primacy 4', '205/55 R16 91V');
        $changes->existing(
            $vehicle,
            new TyreChangeData(new DateTimeImmutable('2025-10-03', new DateTimeZone('UTC')), '20000.000'),
            array_map(
                static fn (P $p): NewTyre => new NewTyre($p, $data, '8.000'),
                [P::FrontLeft, P::FrontRight, P::RearLeft, P::RearRight],
            ),
            $zone,
            'en_GB',
        );
        $depths = [];
        foreach ($this->service($app, TyreService::class)->overview($vehicle, $this->owner($app))->fitted as $view) {
            if ($view !== null) {
                $depths[$view->tyre->id] = '5.000';
            }
        }
        $changes->check(
            $vehicle,
            new TyreChangeData(new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')), '30000.000'),
            $depths,
            $zone,
            'en_GB',
        );
    }
}
