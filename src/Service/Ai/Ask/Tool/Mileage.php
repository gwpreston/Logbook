<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskPeriod;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Support\Number\Decimal;

/**
 * `mileage(vehicle?, period)`: distance driven in a period from the
 * odometer readings, as Reports counts it, with the averages over the
 * whole mileage log and the latest reading (spec.md §7.26).
 */
final readonly class Mileage implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private OdometerService $odometer,
    ) {
    }

    public function name(): string
    {
        return 'mileage';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Distance driven in a period, from odometer readings, per vehicle and in total; also the average '
            . 'per month and per year over the whole mileage log and the latest odometer reading. Leave vehicle '
            . 'out for every vehicle.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicle' => ['type' => 'integer', 'description' => 'Vehicle id.'],
                    ...AskPeriod::SCHEMA,
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return true;
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $vehicle = $this->kit->optionalVehicle($user, $arguments);
        $vehicles = $vehicle === null ? $this->kit->fleet($user) : [$vehicle];
        $period = $this->kit->period($user, $arguments);
        $zone = $user->preferences->timeZone();

        $rows = [];
        $total = '0';
        $counted = 0;
        foreach ($vehicles as $each) {
            $history = $this->odometer->history($each);
            $km = PeriodDistance::km($history->readings, $period->reportPeriod(), $zone);
            if ($km !== null) {
                $total = Decimal::add($total, $km);
                $counted++;
            }
            $perMonth = $history->averageKmPerMonth();
            $latest = $history->latest();
            $rows[] = [
                'vehicle' => $this->kit->vehicleRef($each),
                'distance' => $this->kit->distance($km),
                'average_per_month' => $perMonth === null ? null : $this->kit->distance(Decimal::fromFloat($perMonth, 1)),
                'average_per_year' => $perMonth === null ? null : $this->kit->distance(Decimal::fromFloat($perMonth * 12, 1)),
                'latest_odometer' => $latest === null ? null : [
                    'km' => $latest->readingKm,
            'display' => $this->kit->format->distance($latest->readingKm),
                    'date' => $this->kit->format->instantDate($latest->recordedAt),
                ],
            ];
        }

        $figures = [];
        foreach ($rows as $row) {
            if ($row['distance'] !== null) {
                $figures[] = $row['vehicle']['name'] . ': ' . $row['distance']['display'];
            }
        }

        return new ToolResult(
            [
                'period' => $period->toArray() + ['label' => $this->kit->periodLabel($period)],
                'count' => count($rows),
                'vehicles' => array_slice($rows, 0, ToolKit::LIST_CAP),
                'total_distance' => $vehicle === null && $counted > 0 ? $this->kit->distance($total) : null,
                'note' => 'A vehicle without a distance has fewer than two odometer readings around the period.',
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.mileage'),
                $vehicle?->name() ?? $this->kit->t('ask.source.all_vehicles'),
                $this->kit->periodLabel($period),
            ]),
            array_slice($figures, 0, 5),
            $vehicle === null ? '/garage' : '/vehicles/' . $vehicle->id . '/odometer',
            array_map(static fn (Vehicle $v): int => $v->id, $vehicles),
        );
    }
}
