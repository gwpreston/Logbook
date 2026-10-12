<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Household;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\UserRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\User\UserAdmin;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Security\PasswordHasher;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\Golden\GoldenHousehold;
use Logbook\Tests\Support\Golden\InputBuilder;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The golden household (Phase 45.1) with the people Phase 45.2's journeys
 * and leak sweep need on top, added through the services after it is
 * loaded, so the golden inputs and expected.csv stay as they are:
 *
 * - alex: the admin, owns the Golf, the EV and the Focus (sold); View with
 *   costs on the Corolla. Sees everything: the sweep's control.
 * - sam: owns the Corolla; Log without costs on the Golf.
 * - jo: Manage with costs on the Golf (the partner), sent its reminders.
 * - vic: View with costs on the EV, sent its reminders.
 * - adm: an admin with no vehicle and no share.
 *
 * Everyone signs in with the given password. Every module is on.
 */
final class Household
{
    /**
     * What each person may see (spec.md §5, §7.21): the vehicles, those
     * whose costs, and whether they see other drivers' trips on them
     * (Manage and the owner do; Log and View see only their own).
     *
     * @var array<string, array{vehicles: list<string>, costs: list<string>, othersTrips: bool}>
     */
    public const array PEOPLE = [
        'alex' => [
            'vehicles' => ['golf', 'ev', 'focus', 'corolla'],
            'costs' => ['golf', 'ev', 'focus', 'corolla'],
            'othersTrips' => true,
        ],
        'sam' => ['vehicles' => ['golf', 'corolla'], 'costs' => ['corolla'], 'othersTrips' => false],
        'jo' => ['vehicles' => ['golf'], 'costs' => ['golf'], 'othersTrips' => true],
        'vic' => ['vehicles' => ['ev'], 'costs' => ['ev'], 'othersTrips' => false],
        'adm' => ['vehicles' => [], 'costs' => [], 'othersTrips' => false],
    ];

    /** Everyone but the control. */
    public const array RESTRICTED = ['sam', 'jo', 'vic', 'adm'];

    /** @var array<string, User> */
    private array $added = [];

    private function __construct(
        private readonly ContainerInterface $container,
        public readonly GoldenHousehold $golden,
    ) {
    }

    /**
     * Load the golden household and add jo, vic and adm. The container's
     * clock should already be pinned to InputBuilder::TODAY_UTC.
     */
    public static function load(ContainerInterface $container, string $password): self
    {
        $household = new self($container, GoldenHousehold::load($container, $password));
        $household->addPeople($password);

        return $household;
    }

    public function user(string $key): User
    {
        $user = $this->added[$key] ?? $this->golden->users[$key] ?? throw new RuntimeException("No user {$key}");

        // Re-read: an admin's changes (a share, a password) are not on the loaded object.
        return $this->get(UserRepository::class)->find($user->id) ?? throw new RuntimeException("{$key} is gone");
    }

    public function vehicle(string $key): Vehicle
    {
        return $this->golden->vehicle($key);
    }

    /**
     * @return array<string, Vehicle>
     */
    public function vehicles(): array
    {
        return $this->golden->vehicles;
    }

    /** The key of the vehicle with this id. */
    public function vehicleKey(int $id): string
    {
        foreach ($this->golden->vehicles as $key => $vehicle) {
            if ($vehicle->id === $id) {
                return $key;
            }
        }

        throw new RuntimeException("No vehicle {$id}");
    }

    /**
     * @return list<string> person keys, the control first
     */
    public static function people(): array
    {
        return array_keys(self::PEOPLE);
    }

    private function addPeople(string $password): void
    {
        $admin = $this->golden->user('alex');
        $hash = $this->get(PasswordHasher::class)->hash($password);
        $now = new DateTimeImmutable(InputBuilder::TODAY_UTC, new DateTimeZone('UTC'));
        $london = new DisplayPreferences(
            'en_GB',
            'Europe/London',
            DistanceUnit::Mile,
            VolumeUnit::Litre,
            ConsumptionUnit::MpgUk,
            'GBP',
        );
        foreach (['jo' => ['Jo', false], 'vic' => ['Vic', false], 'adm' => ['Adrian', true]] as $key => [$name, $isAdmin]) {
            $this->added[$key] = $this->get(UserAdmin::class)
                ->addUser($admin, $key, $name, $key . '@example.test', $isAdmin, $london);
        }
        // An added user signs in once they set a password; Sam came in the same way.
        foreach (['sam', 'jo', 'vic', 'adm'] as $key) {
            $this->get(UserRepository::class)->updatePasswordHash($this->user($key)->id, $hash, $now);
        }
        $sharing = $this->get(SharingService::class);
        foreach ([['golf', 'jo', ShareLevel::Manage], ['ev', 'vic', ShareLevel::View]] as [$vehicle, $user, $level]) {
            if ($sharing->add($this->vehicle($vehicle), $user, $level, true, true) !== null) {
                throw new RuntimeException("Share of {$vehicle} with {$user} refused");
            }
        }
        $this->get(FeatureToggles::class)->save(Feature::cases());
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function get(string $id): object
    {
        $service = $this->container->get($id);
        if (!$service instanceof $id) {
            throw new RuntimeException("{$id} is not in the container");
        }

        return $service;
    }
}
