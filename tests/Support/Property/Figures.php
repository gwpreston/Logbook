<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Property;

use DateTimeImmutable;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Forecast\Forecast;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Report\VehicleTrueCost;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The money figures a user sees, read through the services the pages use:
 * Reports over all time, the last 12 months and each calendar year, and
 * each vehicle's cost of ownership and true cost. Flattened to strings keyed
 * by what they are and the vehicle's model (never an id), so two loads of
 * the same household can be compared.
 */
final class Figures
{
    public const string TODAY = '2026-06-15';
    public const int FIRST_YEAR = 2023;

    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return array<string, Report> keyed `all`, `12m` or `year 2024`
     */
    public function reports(User $user, array $vehicles): array
    {
        $today = self::today();
        $filters = [
            'all' => new ReportFilter(ReportPeriod::preset(ReportRange::AllTime, $today), includeArchived: true),
            '12m' => new ReportFilter(ReportPeriod::preset(ReportRange::TwelveMonths, $today), includeArchived: true),
        ];
        for ($year = self::FIRST_YEAR; $year <= (int) $today->format('Y'); $year++) {
            $filters["year {$year}"] = new ReportFilter(new ReportPeriod(
                ReportRange::Custom,
                self::date("{$year}-01-01"),
                $year === (int) $today->format('Y') ? $today : self::date("{$year}-12-31"),
            ), includeArchived: true);
        }

        $reports = [];
        $built = $this->get(ReportService::class)->compare($user, $vehicles, array_values($filters));
        foreach (array_keys($filters) as $i => $name) {
            $reports[$name] = $built[$i];
        }

        return $reports;
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<VehicleTrueCost>
     */
    public function trueCosts(User $user, array $vehicles): array
    {
        return $this->get(TrueCostService::class)->forVehicles($user, $vehicles, self::today());
    }

    /**
     * *Coming up* as of today.
     *
     * @param list<Vehicle> $vehicles
     */
    public function forecast(User $user, array $vehicles): Forecast
    {
        return $this->get(ComingUp::class)->forecast($user, $vehicles);
    }

    /**
     * Every figure, flattened.
     *
     * @param list<Vehicle> $vehicles
     * @return array<string, string>
     */
    public function snapshot(User $user, array $vehicles, bool $withCounts = true): array
    {
        $figures = [];
        foreach ($this->reports($user, $vehicles) as $name => $report) {
            foreach ($report->currencies as $currency) {
                $at = "report.{$name}.{$currency->currency}";
                $figures["{$at}.total"] = self::money($currency->total);
                $figures["{$at}.km"] = (string) $currency->distanceKm;
                $figures["{$at}.per_km"] = (string) $currency->costPerKm;
                $figures["{$at}.per_month"] = self::money($currency->averagePerMonth);
                if ($withCounts) {
                    $figures["{$at}.count"] = (string) $currency->count;
                }
                foreach ($currency->groups as $group) {
                    $figures["{$at}.group.{$group->group->value}"] = self::money($group->amount);
                }
                foreach ($currency->months as $month) {
                    $figures["{$at}.month." . $month->month->format('Y-m')] = self::money($month->total);
                }
                foreach ($currency->vehicles as $vehicle) {
                    $figures["{$at}.vehicle.{$vehicle->vehicle->data->model}.total"] = self::money($vehicle->total);
                    $figures["{$at}.vehicle.{$vehicle->vehicle->data->model}.per_km"] = (string) $vehicle->costPerKm;
                }
            }
        }
        foreach ($this->trueCosts($user, $vehicles) as $result) {
            $model = $result->vehicle->data->model;
            $ownership = $result->ownership;
            $figures["ownership.{$model}.running"] = self::money($ownership->running);
            $figures["ownership.{$model}.total"] = self::money($ownership->total);
            $figures["ownership.{$model}.depreciation"] = self::money($ownership->depreciationCost);
            $figures["ownership.{$model}.per_km"] = (string) $ownership->perKm;
            $figures["ownership.{$model}.per_month"] = self::money($ownership->perMonth);
            $periods = ['since' => $result->sinceBought, '12m' => $result->lastTwelveMonths];
            foreach ($result->years as $trueCost) {
                $periods['year.' . $trueCost->period->year] = $trueCost;
            }
            foreach ($periods as $name => $trueCost) {
                if ($trueCost === null) {
                    continue;
                }
                $figures += self::trueCost("true.{$model}.{$name}", $trueCost);
            }
        }
        ksort($figures);

        return $figures;
    }

    /**
     * @return array<string, string>
     */
    private static function trueCost(string $at, TrueCost $trueCost): array
    {
        $figures = [
            "{$at}.total" => self::money($trueCost->total()),
            "{$at}.payouts" => self::money($trueCost->payouts),
            "{$at}.depreciation" => self::money($trueCost->depreciation),
            "{$at}.per_km" => (string) $trueCost->perKm,
        ];
        foreach ($trueCost->amounts as $part => $amount) {
            $figures["{$at}.part.{$part}"] = self::money($amount);
        }
        foreach ($trueCost->rates as $part => $rate) {
            $figures["{$at}.rate.{$part}"] = $rate;
        }

        return $figures;
    }

    public static function today(): DateTimeImmutable
    {
        return self::date(self::TODAY);
    }

    private static function date(string $date): DateTimeImmutable
    {
        return LocalTime::parseDate($date) ?? throw new RuntimeException("Not a date: {$date}");
    }

    private static function money(?Money $money): string
    {
        return $money === null ? '' : $money->currency . ' ' . $money->micros;
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
