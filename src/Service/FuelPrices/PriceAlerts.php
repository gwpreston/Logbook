<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\PriceAlert;
use Logbook\Domain\Station\Station;
use Logbook\Domain\User\User;
use Logbook\Repository\PriceAlertRepository;
use Logbook\Repository\StationRepository;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * Setting and removing price alerts (spec.md §7.34 *Price alerts*): on a
 * favourite station linked to the enabled provider, per grade it lists,
 * up to 20 per user.
 */
final readonly class PriceAlerts
{
    public const int MAX_PER_USER = 20;
    public const string MIN_PRICE = '0.001';
    public const string MAX_PRICE = '99.999';

    public function __construct(
        private PriceAlertRepository $alerts,
        private StationRepository $stations,
        private ListedPrices $listed,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Every alert the user has set (the API's list, spec.md §7.20).
     *
     * @return list<PriceAlert>
     */
    public function forUser(User $user): array
    {
        return $this->alerts->forUser($user->id);
    }

    /**
     * @return array<string, PriceAlert> by grade code
     */
    public function forStation(User $user, Station $station): array
    {
        $byGrade = [];
        foreach ($this->alerts->forStation($user->id, $station->id) as $alert) {
            $byGrade[$alert->grade->value] = $alert;
        }

        return $byGrade;
    }

    /**
     * Whether the user may set alerts on this station now.
     */
    public function canAlert(User $user, Station $station): bool
    {
        return $this->listed->forStation($station) !== null
            && in_array($station->id, $this->stations->favouriteIds($user->id), true);
    }

    /**
     * @param string $below canonical decimal, per litre
     * @throws PriceAlertRefused
     */
    public function set(User $user, Station $station, FuelGrade $grade, string $below): void
    {
        $prices = $this->listed->forStation($station);
        if ($prices === null || !in_array($station->id, $this->stations->favouriteIds($user->id), true)) {
            throw new PriceAlertRefused('not_favourite');
        }
        if (!in_array($grade, $prices->providerStation->data->grades, true) && $prices->price($grade->value) === null) {
            throw new PriceAlertRefused('grade');
        }
        if (
            !Decimal::isCanonical($below)
            || Decimal::compare($below, self::MIN_PRICE) < 0
            || Decimal::compare($below, self::MAX_PRICE) > 0
        ) {
            throw new PriceAlertRefused('price');
        }
        if (!isset($this->forStation($user, $station)[$grade->value]) && $this->alerts->count($user->id) >= self::MAX_PER_USER) {
            throw new PriceAlertRefused('limit');
        }
        $this->alerts->save($user->id, $station->id, $grade, Decimal::round($below, 3), $this->clock->now());
    }

    public function remove(User $user, Station $station, FuelGrade $grade): void
    {
        $this->alerts->delete($user->id, $station->id, $grade);
    }
}
