<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Access;

use DateTimeImmutable;
use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\UserRepository;
use Logbook\Service\Access\InstanceAccess;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * The Phase 18.1 policy (spec.md §5 *Access policy*): every ability on
 * one's own vehicles and none on anyone else's, every instance ability;
 * visible ids per scope, remembered until forget().
 */
final class SingleOwnerVehicleAccessTest extends AppTestCase
{
    use CostFixtures;

    public function testOwnVehiclesOnlyWithEveryAbility(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $golf = $this->vehicle($app);
        $other = $this->service($app, UserRepository::class)->insert(
            'other',
            'not-a-hash',
            'Sam Other',
            DisplayPreferences::defaults('en_GB', 'Europe/London', 'GBP'),
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
        );
        $theirs = $this->service($app, VehicleService::class)
            ->create($other, new VehicleData(VehicleType::Car, 'Skoda', 'Octavia', FuelType::Diesel));

        $access = $this->service($app, VehicleAccess::class);
        foreach (VehicleAbility::cases() as $ability) {
            self::assertTrue($access->can($owner, $ability, $golf), $ability->value);
            self::assertFalse($access->can($owner, $ability, $theirs), $ability->value);
        }
        self::assertSame([$golf->id], $access->visibleVehicleIds($owner, VehicleScope::All));
        self::assertSame([$theirs->id], $access->visibleVehicleIds($other, VehicleScope::All));

        $instance = $this->service($app, InstanceAccess::class);
        foreach (InstanceAbility::cases() as $ability) {
            self::assertTrue($instance->can($owner, $ability), $ability->value);
        }
    }

    public function testVisibleIdsFollowTheScopeAndArchiving(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $golf = $this->vehicle($app);
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $access = $this->service($app, VehicleAccess::class);

        self::assertSame([$golf->id, $polo->id], $access->visibleVehicleIds($owner, VehicleScope::Active));
        self::assertSame([], $access->visibleVehicleIds($owner, VehicleScope::Archived));

        $this->service($app, VehicleService::class)->archive($owner, $polo);

        self::assertSame([$golf->id], $access->visibleVehicleIds($owner, VehicleScope::Active), 'archiving forgets');
        self::assertSame([$polo->id], $access->visibleVehicleIds($owner, VehicleScope::Archived));
        self::assertSame([$golf->id, $polo->id], $access->visibleVehicleIds($owner, VehicleScope::All));
    }

    public function testRemembersUntilForgotten(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $golf = $this->vehicle($app);
        $access = $this->service($app, VehicleAccess::class);
        $first = $access->visibleVehicleIds($owner, VehicleScope::Active);

        // Written behind the service's back: the remembered answer stands until forget().
        $this->connection($app)->executeStatement('DELETE FROM vehicles');
        $remembered = $access->visibleVehicleIds($owner, VehicleScope::Active);
        self::assertSame([$golf->id], $first);
        self::assertSame($first, $remembered);

        $access->forget();
        self::assertSame([], $access->visibleVehicleIds($owner, VehicleScope::Active));
    }
}
