<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Property;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Tests\Support\Golden\Rng;

/**
 * Generates a small household from a seed (Phase 45.1 property tests): one
 * to three vehicles in GBP, EUR or JPY, about 60 entries in all, the owner
 * and a second member in different time zones. The same seed gives the same
 * household on every machine.
 *
 * Kept out of what Phase 45.1.5 has still to fix, so every property holds
 * on today's code: every vehicle's log reaches back to its purchase (#376).
 * One vehicle in four is valued on its purchase day, that day's closing
 * value (#389). One unsold vehicle in two is bought on HP, at 0% now and
 * then, and half of those are settled early, sometimes for far less than
 * the payments left, so the end adjustment goes below zero (#387); every
 * finance amount is in the currency's minor unit (#390). No two timed entries of a vehicle share an instant, and
 * no two valuations share a day, so the order they are entered in can't
 * matter (spec §7.1, §7.2).
 */
final class HouseholdGenerator
{
    public const string TODAY = '2026-06-15T10:00:00Z';
    public const array ZONES = [
        'Europe/London', 'Europe/Dublin', 'Australia/Sydney', 'America/Los_Angeles', 'Pacific/Auckland', 'Asia/Kolkata',
    ];
    private const array CURRENCIES = ['GBP', 'GBP', 'EUR', 'JPY'];
    private const array CATEGORIES = ['parking', 'tolls', 'cleaning', 'accessories', 'fines', 'other', 'tax'];

    private Rng $rng;

    public function generate(int $seed): Plan
    {
        $this->rng = new Rng($seed);
        $ownerZone = $this->pick(self::ZONES);
        do {
            $memberZone = $this->pick(self::ZONES);
        } while ($memberZone === $ownerZone);

        $vehicles = [];
        $count = $this->rng->between(1, 3);
        for ($i = 0; $i < $count; $i++) {
            $vehicles[] = $this->vehicle('v' . ($i + 1), $ownerZone, $i > 0);
        }

        return new Plan($seed, self::TODAY, $ownerZone, $memberZone, $vehicles);
    }

    private function vehicle(string $key, string $zone, bool $memberSeesCosts): VehiclePlan
    {
        $currency = $this->pick(self::CURRENCIES);
        $scale = $currency === 'JPY' ? 150 : 1;
        $today = new DateTimeImmutable(substr(self::TODAY, 0, 10));
        $purchase = (new DateTimeImmutable('2023-01-01'))->modify('+' . $this->rng->between(0, 970) . ' days');
        $sold = $this->rng->oneIn(4);
        $end = $sold
            ? $purchase->modify('+' . $this->rng->between(150, max(150, self::days($purchase, $today) - 20)) . ' days')
            : $today->modify('-1 day');
        $price = $this->rng->between(3000, 30000) * 100 * $scale;

        $entries = [];
        $span = self::days($purchase, $end);
        $startKm = $this->rng->between(10000, 90000) * 1000;
        $km = $startKm;
        $previous = 0;
        foreach ($this->distinctDays($this->rng->between(6, 22), $span) as $offset) {
            $day = $purchase->modify("+{$offset} days");
            $km += ($offset - $previous) * $this->rng->between(150, 700) * 100;
            $previous = $offset;
            $entries[] = $this->fill($day, $km, $zone, $currency);
        }
        for ($i = $this->rng->between(0, 8); $i > 0; $i--) {
            $entries[] = new Entry(
                'expense',
                $this->amount($currency, 30000),
                $this->dayIn($purchase, $span),
                category: $this->pick(self::CATEGORIES),
            );
        }
        for ($i = $this->rng->between(0, 3); $i > 0; $i--) {
            $entries[] = new Entry('maintenance', $this->amount($currency, 80000), $this->dayIn($purchase, $span));
        }
        for ($i = $this->rng->between(0, 2); $i > 0; $i--) {
            $start = new DateTimeImmutable($this->dayIn($purchase, $span));
            $entries[] = new Entry(
                'document',
                $this->amount($currency, 90000),
                $start->format('Y-m-d'),
                expiry: $start->modify('+1 year -1 day')->format('Y-m-d'),
            );
        }
        if (!$sold) {
            // The current policy, so a renewal with a known cost is always coming up.
            $start = $today->modify('-' . $this->rng->between(1, min(330, max(1, $span))) . ' days');
            $entries[] = new Entry(
                'document',
                $this->amount($currency, 90000),
                $start->format('Y-m-d'),
                expiry: $start->modify('+1 year -1 day')->format('Y-m-d'),
            );
        }
        $valued = $this->distinctDays($this->rng->between(0, 3), $span);
        if ($this->rng->oneIn(4)) {
            array_unshift($valued, 0);
        }
        foreach ($valued as $offset) {
            $entries[] = new Entry(
                'valuation',
                self::fixed(intdiv($price * $this->rng->between(30, 105), 100), 2),
                $purchase->modify("+{$offset} days")->format('Y-m-d'),
            );
        }
        if ($this->rng->oneIn(3)) {
            // An insurance payout, now and then larger than everything else spent.
            $entries[] = new Entry(
                'payout',
                $this->amount($currency, $this->rng->oneIn(3) ? 2000000 : 150000),
                $this->dayIn($purchase, $span),
                excess: $this->amount($currency, 50000),
            );
        }

        $finance = !$sold && $this->rng->oneIn(2) ? $this->finance($purchase, $today, $price, $currency) : null;

        return new VehiclePlan(
            $key,
            $currency,
            $purchase->format('Y-m-d'),
            self::fixed($price, 2),
            self::fixed($startKm, 3),
            $sold ? $end->format('Y-m-d') : null,
            $sold ? self::fixed(intdiv($price * $this->rng->between(20, 115), 100), 2) : null,
            $memberSeesCosts,
            $entries,
            $finance,
        );
    }

    /**
     * HP on the purchase price ($price in hundredths of the currency's unit).
     * The payment is worked out from the APR and typed to the minor unit.
     */
    private function finance(DateTimeImmutable $purchase, DateTimeImmutable $today, int $price, string $currency): FinancePlan
    {
        $places = $currency === 'JPY' ? 0 : 2;
        $minor = $currency === 'JPY' ? 100 : 1;
        $deposit = intdiv($price * $this->rng->between(0, 30), 100 * $minor) * $minor;
        $credit = ($price - $deposit) / 100;
        $payments = $this->rng->between(12, 48);
        $apr = $this->rng->oneIn(4) ? 0 : $this->rng->between(19, 129);
        $rate = (1 + $apr / 1000) ** (1 / 12) - 1;
        $payment = $apr === 0 ? $credit / $payments : $credit * $rate / (1 - (1 + $rate) ** -$payments);
        $regular = (int) round($payment * 10 ** $places);

        $settledOn = null;
        $settlement = null;
        $elapsed = (int) floor(self::days($purchase, $today) / 31);
        if ($elapsed >= 4 && $this->rng->oneIn(2)) {
            $made = $this->rng->between(2, min($elapsed, $payments) - 1);
            $settledOn = $purchase->modify('+' . $made . ' months +' . $this->rng->between(1, 20) . ' days');
            // Sometimes far below the payments left: the adjustment goes negative (#387).
            $left = ($payments - $made) * $regular;
            $percent = $this->rng->oneIn(3) ? $this->rng->between(20, 50) : $this->rng->between(80, 100);
            $settlement = intdiv($left * $percent, 100);
        }

        return new FinancePlan(
            self::fixed($deposit, 2),
            $payments,
            self::fixed($regular, $places),
            self::fixed($apr, 1),
            $settledOn?->format('Y-m-d'),
            $settlement === null ? null : self::fixed($settlement, $places),
        );
    }

    private function fill(DateTimeImmutable $day, int $km, string $zone, string $currency): Entry
    {
        // Now and then at 23:30 on the last day of a month, the case a time zone gets wrong.
        $lastDay = $day->format('t') === $day->format('d');
        $time = $lastDay && $this->rng->oneIn(2)
            ? '23:30'
            : sprintf('%02d:%02d', $this->rng->between(6, 22), $this->rng->between(0, 59));
        $at = (new DateTimeImmutable($day->format('Y-m-d') . ' ' . $time, new DateTimeZone($zone)))
            ->setTimezone(new DateTimeZone('UTC'));
        $volume = $this->rng->between(15000, 55000);
        $price = $currency === 'JPY' ? $this->rng->between(150000, 200000) : $this->rng->between(1200, 1900);
        // The pump's total, half up to the currency's minor unit.
        $minor = $currency === 'JPY' ? intdiv($volume * $price + 500000, 1000000) : intdiv($volume * $price + 5000, 10000);

        return new Entry(
            'fill',
            self::fixed($minor, $currency === 'JPY' ? 0 : 2),
            at: $at->format('Y-m-d\TH:i:s\Z'),
            km: self::fixed($km, 3),
            volume: self::fixed($volume, 3),
            price: self::fixed($price, 3),
            partial: $this->rng->oneIn(8),
        );
    }

    /**
     * An amount up to $maxMinor minor units (pence or cents; ×150 for yen), zero one time in ten, and
     * now and then with a third decimal below the minor unit.
     */
    private function amount(string $currency, int $maxMinor): string
    {
        if ($this->rng->oneIn(10)) {
            return '0.00';
        }
        $minor = $this->rng->between(1, $maxMinor);
        $whole = $currency === 'JPY' ? intdiv($minor * 150, 100) : $minor;
        // One in five below the minor unit (£1.005, ¥120.5): the forms take 3 decimals in every currency.
        if ($this->rng->oneIn(5)) {
            $step = $currency === 'JPY' ? 1000 : 10;

            return self::fixed($whole * $step + $this->rng->between(1, $step - 1), 3);
        }

        return $currency === 'JPY' ? (string) $whole : self::fixed($whole, 2);
    }

    /**
     * $n different day offsets from 1 to $span, in order.
     *
     * @return list<int>
     */
    private function distinctDays(int $n, int $span): array
    {
        $days = [];
        $n = min($n, $span);
        while (count($days) < $n) {
            $days[$this->rng->between(1, $span)] = true;
        }
        $offsets = array_keys($days);
        sort($offsets);

        return $offsets;
    }

    private function dayIn(DateTimeImmutable $from, int $span): string
    {
        return $from->modify('+' . $this->rng->between(0, $span) . ' days')->format('Y-m-d');
    }

    /**
     * @template T
     * @param non-empty-list<T> $list
     * @return T
     */
    private function pick(array $list): mixed
    {
        return $list[$this->rng->between(0, count($list) - 1)];
    }

    private static function days(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->days;
    }

    private static function fixed(int $units, int $places): string
    {
        if ($places === 0) {
            return (string) $units;
        }
        $scale = 10 ** $places;

        return intdiv($units, $scale) . '.' . str_pad((string) ($units % $scale), $places, '0', STR_PAD_LEFT);
    }
}
