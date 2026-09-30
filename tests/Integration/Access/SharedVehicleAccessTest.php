<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Access;

use DateTimeImmutable;
use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Access\InstanceAccess;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Phase 19 policy (spec.md §5 *Access policy*, §7.21): every ability on
 * one's own vehicles, a share's level on shared ones and none on anyone
 * else's; instance abilities for admins only; visible ids per scope,
 * remembered until forget().
 */
final class SharedVehicleAccessTest extends AppTestCase
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
            self::assertTrue($instance->can($owner, $ability), 'the setup user is an admin: ' . $ability->value);
            self::assertFalse($instance->can($other, $ability), 'a member is not: ' . $ability->value);
        }
    }

    /**
     * @return iterable<string, array{ShareLevel, bool, list<VehicleAbility>}>
     */
    public static function levels(): iterable
    {
        yield 'view' => [ShareLevel::View, false, [VehicleAbility::View]];
        yield 'view with costs' => [ShareLevel::View, true, [VehicleAbility::View, VehicleAbility::ViewCosts]];
        yield 'log' => [ShareLevel::Log, false, [VehicleAbility::View, VehicleAbility::Log]];
        yield 'log with costs' => [ShareLevel::Log, true, [VehicleAbility::View, VehicleAbility::Log, VehicleAbility::ViewCosts]];
        yield 'manage (always with costs)' => [
            ShareLevel::Manage,
            false,
            [VehicleAbility::View, VehicleAbility::Log, VehicleAbility::Manage, VehicleAbility::ViewCosts, VehicleAbility::ViewOthersTrips],
        ];
    }

    /**
     * @param list<VehicleAbility> $expected
     */
    #[DataProvider('levels')]
    public function testAShareGrantsItsLevelAndNeverOwn(ShareLevel $level, bool $costs, array $expected): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $partner = $this->createMember($app);
        $access = $this->service($app, VehicleAccess::class);
        self::assertFalse($access->can($partner, VehicleAbility::View, $golf), 'nothing before the share');

        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $partner->id, $level, $costs, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $access->forget();

        foreach (VehicleAbility::cases() as $ability) {
            self::assertSame(in_array($ability, $expected, true), $access->can($partner, $ability, $golf), $ability->value);
        }
        self::assertSame([$golf->id], $access->visibleVehicleIds($partner, VehicleScope::All));
        self::assertSame([], $access->recipientVehicleIds($partner), 'seeing is not asking for reminders');
    }

    public function testRecipientsAreTheOwnerAndSharesWithNotify(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $owner = $this->owner($app);
        $golf = $this->vehicle($app);
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $partner = $this->createMember($app);
        $shares = $this->service($app, VehicleShareRepository::class);
        $now = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $shares->insert($golf->id, $partner->id, ShareLevel::View, false, true, $now);
        $shares->insert($polo->id, $partner->id, ShareLevel::Manage, true, false, $now);
        $access = $this->service($app, VehicleAccess::class);

        self::assertSame([$golf->id, $polo->id], $access->recipientVehicleIds($owner));
        self::assertSame([$golf->id], $access->recipientVehicleIds($partner));

        $this->service($app, VehicleService::class)->archive($owner, $golf);
        self::assertSame([$polo->id], $access->recipientVehicleIds($owner), 'archived vehicles send nothing');
        self::assertSame([], $access->recipientVehicleIds($partner));
    }

    public function testADisabledUserHasNothing(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $partner = $this->createMember($app, isAdmin: true);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $partner->id, ShareLevel::Manage, true, true, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $disabled = new User(
            $partner->id,
            $partner->username,
            $partner->passwordHash,
            $partner->displayName,
            $partner->preferences,
            $partner->createdAt,
            $partner->updatedAt,
            true,
            new DateTimeImmutable('2026-09-02T00:00:00Z'),
        );

        $access = $this->service($app, VehicleAccess::class);
        self::assertTrue($access->can($partner, VehicleAbility::View, $golf));
        self::assertFalse($access->can($disabled, VehicleAbility::View, $golf));
        self::assertSame([], $access->visibleVehicleIds($disabled, VehicleScope::All));
        self::assertSame([], $access->recipientVehicleIds($disabled));
        self::assertTrue($this->service($app, InstanceAccess::class)->can($partner, InstanceAbility::Backup));
        self::assertFalse($this->service($app, InstanceAccess::class)->can($disabled, InstanceAbility::Backup));
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
