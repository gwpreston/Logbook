<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;

/**
 * One currency's claim totals (spec.md §7.23): the distance at each rate,
 * the passenger amount, the approved amount and, with employer rates, what
 * the employer paid and the difference. Every total is the sum of the
 * trips' rounded amounts, and the rate lines add up to the mileage amount.
 */
final readonly class ClaimTotals
{
    /**
     * @param list<array{unit: DistanceUnit, rate: string, distance: string, amount: string}> $rates in the order first used
     */
    public function __construct(
        public string $currency,
        public array $rates,
        public string $mileageAmount,
        public string $passengerAmount,
        /** Null when no trip's rate set has employer rates. */
        public ?string $employerAmount,
        public int $tripCount,
    ) {
    }

    /**
     * Totals per currency, in the order each currency first appears.
     * Trips with no rate set in effect are left out (the report counts them
     * separately).
     *
     * @param list<ValuedTrip> $trips
     * @return list<self>
     */
    public static function byCurrency(array $trips): array
    {
        /** @var array<string, array{rates: array<string, array{unit: DistanceUnit, rate: string, distance: string, amount: string}>, mileage: string, passengers: string, employer: ?string, count: int}> $groups */
        $groups = [];
        foreach ($trips as $trip) {
            $currency = $trip->currency();
            $unit = $trip->unit();
            if ($currency === null || $unit === null || $trip->mileageAmount === null) {
                continue;
            }
            $group = $groups[$currency]
                ?? ['rates' => [], 'mileage' => '0', 'passengers' => '0', 'employer' => null, 'count' => 0];
            foreach ($trip->lines as $line) {
                $key = $unit->value . '|' . Decimal::trim($line->rate);
                $rate = $group['rates'][$key] ?? ['unit' => $unit, 'rate' => $line->rate, 'distance' => '0', 'amount' => '0'];
                $rate['distance'] = Decimal::add($rate['distance'], $line->distance);
                $rate['amount'] = Decimal::add($rate['amount'], $line->amount);
                $group['rates'][$key] = $rate;
            }
            $group['mileage'] = Decimal::add($group['mileage'], $trip->mileageAmount);
            $group['passengers'] = Decimal::add($group['passengers'], $trip->passengerAmount ?? '0');
            if ($trip->employerAmount !== null) {
                $group['employer'] = Decimal::add($group['employer'] ?? '0', $trip->employerAmount);
            }
            $group['count']++;
            $groups[$currency] = $group;
        }

        $totals = [];
        foreach ($groups as $currency => $group) {
            $totals[] = new self(
                currency: $currency,
                rates: array_values($group['rates']),
                mileageAmount: $group['mileage'],
                passengerAmount: $group['passengers'],
                employerAmount: $group['employer'],
                tripCount: $group['count'],
            );
        }

        return $totals;
    }

    /**
     * Mileage plus passengers.
     */
    public function approvedAmount(): string
    {
        return Decimal::add($this->mileageAmount, $this->passengerAmount);
    }

    /**
     * Approved mileage (without passengers) minus what the employer paid:
     * positive = not paid, negative = paid above. Passenger payments get no
     * tax relief when unpaid, so they never count here (spec.md §7.23).
     */
    public function difference(): ?string
    {
        return $this->employerAmount === null ? null : Decimal::subtract($this->mileageAmount, $this->employerAmount);
    }

    public function totalDistance(): string
    {
        $sum = '0';
        foreach ($this->rates as $rate) {
            $sum = Decimal::add($sum, $rate['distance']);
        }

        return $sum;
    }
}
