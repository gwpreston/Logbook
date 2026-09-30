<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use DateTimeImmutable;
use Logbook\Domain\Trip\MileageRateSet;
use Logbook\Domain\Trip\MileageRateSetData;
use Logbook\Domain\User\User;
use Logbook\Repository\MileageRateSetRepository;
use Psr\Clock\ClockInterface;

/**
 * A user's dated mileage rates (spec.md §7.23). GB users are given HMRC's
 * the first time they need rates (RateProvider).
 */
final readonly class MileageRateService
{
    public function __construct(
        private MileageRateSetRepository $rates,
        private RateProvider $provider,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<MileageRateSet> newest first
     */
    public function forUser(User $user): array
    {
        $this->provider->ensure($user);

        return $this->rates->listForUser($user->id);
    }

    public function find(User $user, int $id): ?MileageRateSet
    {
        return $this->rates->find($user->id, $id);
    }

    /**
     * The set in effect on a date: the latest starting on or before it.
     */
    public function inEffect(User $user, DateTimeImmutable $on): ?MileageRateSet
    {
        return ClaimCalculator::inEffect(ClaimCalculator::byEffectiveDate($this->forUser($user)), $on);
    }

    public function isDateTaken(User $user, DateTimeImmutable $from, ?MileageRateSet $except = null): bool
    {
        return $this->rates->startsOn($user->id, $from, $except?->id);
    }

    public function create(User $user, MileageRateSetData $data): void
    {
        $this->rates->insert($user->id, $data, $this->clock->now());
    }

    public function update(User $user, MileageRateSet $set, MileageRateSetData $data): void
    {
        $this->rates->update($user->id, $set->id, $data, $this->clock->now());
    }

    public function delete(User $user, MileageRateSet $set): void
    {
        $this->rates->delete($user->id, $set->id);
    }
}
