<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Domain\Trip\MileageRateSetData;
use Logbook\Domain\User\User;
use Logbook\Repository\MileageRateSetRepository;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\I18n\Region;
use Logbook\Support\Units\DistanceUnit;
use LogicException;
use Psr\Clock\ClockInterface;

/**
 * HMRC's approved mileage allowance payments, provided once to GB users
 * (spec.md §7.23). They are ordinary rows the user can edit; no release
 * ever changes them. A user who deletes them does not get them back.
 */
final readonly class RateProvider
{
    public const string HMRC_SOURCE = 'HMRC approved mileage allowance payments';

    public function __construct(
        private MileageRateSetRepository $rates,
        private TripSettingsStore $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Provide the GB sets if this user is in GB, has none and has never had
     * them. Returns whether it added them.
     */
    public function ensure(User $user): bool
    {
        if (Region::of($user->preferences->locale) !== 'GB') {
            return false;
        }
        $settings = $this->settings->for($user);
        if ($settings->ratesProvided) {
            return false;
        }

        if ($this->rates->listForUser($user->id) === []) {
            $now = $this->clock->now();
            foreach (self::hmrc() as $set) {
                $this->rates->insert($user->id, $set, $now);
            }
        }
        $this->settings->save($user, $settings->withRatesProvided());

        return true;
    }

    /**
     * @return list<MileageRateSetData>
     */
    public static function hmrc(): array
    {
        $set = static fn (string $from, string $car): MileageRateSetData => new MileageRateSetData(
            effectiveFrom: LocalTime::parseDate($from) ?? throw new LogicException($from),
            distanceUnit: DistanceUnit::Mile,
            currency: 'GBP',
            carRate: $car,
            carThreshold: '10000.000',
            carRateAfter: '0.2500',
            bikeRate: '0.2400',
            passengerRate: '0.0500',
            source: self::HMRC_SOURCE,
        );

        return [$set('2011-04-06', '0.4500'), $set('2026-04-06', '0.5500')];
    }
}
