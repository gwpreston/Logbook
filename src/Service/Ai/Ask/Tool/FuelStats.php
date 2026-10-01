<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Fuel\GradeVerdict;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskPeriod;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\EconomyStatus;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Number\PercentDifference;

/**
 * `fuel_stats(vehicle?, period, grade?)`: fill-ups in a period (spec.md
 * §7.26, Phase 16): economy over the tanks measured in it, volume, spend
 * and price per unit, each by grade, and the grade verdicts (worked out
 * over the whole history, as the fuel page shows them). Spend and prices
 * only with ViewCosts.
 */
final readonly class FuelStats implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private FuelService $fuel,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'fuel_stats';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Fuel (or charging) in a period: number of fill-ups, volume, spend, average price per unit and '
            . 'economy (mpg, L/100 km or the user\'s unit), split by fuel grade, plus whether a grade is worth it. '
            . 'Leave vehicle out for every vehicle.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicle' => ['type' => 'integer', 'description' => 'Vehicle id.'],
                    ...AskPeriod::SCHEMA,
                    'grade' => ['type' => 'string', 'enum' => self::grades(), 'description' => 'Only this grade.'],
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->features->isEnabled(Feature::Fuel);
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $vehicle = $this->kit->optionalVehicle($user, $arguments);
        $period = $this->kit->period($user, $arguments);
        $grade = FuelGrade::tryFrom($arguments->choice('grade', self::grades()) ?? '');
        $vehicles = $vehicle === null ? $this->kit->fleet($user) : [$vehicle];

        $rows = [];
        $figures = [];
        foreach ($vehicles as $each) {
            $row = $this->forVehicle($user, $each, $period, $grade);
            if ($row === null) {
                continue;
            }
            $rows[] = $row['data'];
            $figures = [...$figures, ...$row['figures']];
        }

        return new ToolResult(
            [
                'period' => $period->toArray() + ['label' => $this->kit->periodLabel($period)],
                'grade' => $grade?->value,
                'count' => count($rows),
                'vehicles' => array_slice($rows, 0, ToolKit::LIST_CAP),
                'note' => $rows === []
                    ? 'No fill-ups in this period.'
                    : 'Economy counts only tanks measured full to full that ended in the period.',
            ],
            $this->kit->source([
                $this->kit->t('ask.tool.fuel_stats'),
                $vehicle?->name() ?? $this->kit->t('ask.source.all_vehicles'),
                $this->kit->periodLabel($period),
                $grade === null ? null : $this->kit->t($grade->labelKey()),
            ]),
            array_slice($figures, 0, 6),
            $vehicle === null ? '/garage' : '/vehicles/' . $vehicle->id . '/fuel',
            array_map(static fn (Vehicle $v): int => $v->id, $vehicles),
        );
    }

    /**
     * @return array{data: array<string, mixed>, figures: list<string>}|null null without fill-ups in the period
     */
    private function forVehicle(User $user, Vehicle $vehicle, AskPeriod $period, ?FuelGrade $grade): ?array
    {
        $zone = $user->preferences->timeZone();
        $history = $this->fuel->history($vehicle);
        $fills = array_values(array_filter(
            $history->fills,
            static fn (FillEconomy $fill): bool => $period->contains(LocalTime::dateOf($fill->entry->data->filledAt, $zone))
                && ($grade === null || $fill->entry->data->grade === $grade),
        ));
        if ($fills === []) {
            return null;
        }
        $costs = $this->kit->canSeeCosts($user, $vehicle);
        $currency = $this->kit->currency($user, $vehicle);
        $breakdowns = $this->fuel->gradeBreakdowns($history);

        $kinds = [];
        $figures = [];
        foreach (EnergyKind::cases() as $kind) {
            $ofKind = array_values(array_filter(
                $fills,
                static fn (FillEconomy $f): bool => $f->entry->data->fuel->kind() === $kind,
            ));
            if ($ofKind === []) {
                continue;
            }
            $electric = $kind === EnergyKind::Electric;
            [$totals, $shown] = $this->totals($ofKind, $electric, $costs, $currency);
            $byGrade = [];
            foreach ($this->byGrade($ofKind) as $key => $group) {
                $byGrade[] = [
                    'grade' => $key === '' ? null : $key,
                    'label' => $this->kit->t($key === '' ? 'ask.result.grade_not_recorded' : FuelGrade::from($key)->labelKey()),
                    ...$this->totals($group, $electric, $costs, $currency, $key === '' ? null : FuelGrade::from($key))[0],
                ];
            }
            $breakdown = $breakdowns[$kind->value] ?? null;
            $verdicts = $breakdown === null ? [] : array_map(
                fn (GradeVerdict $v): array => $this->verdict($v),
                $this->fuel->gradeVerdicts($history, $breakdown, $zone),
            );
            $kinds[] = [
                'kind' => $kind->value,
                ...$totals,
                'by_grade' => $byGrade,
                'grade_verdicts' => $verdicts,
            ];
            foreach ($shown as $display) {
                $figures[] = $vehicle->name() . ': ' . $display;
            }
        }

        return [
            'data' => [
                'vehicle' => $this->kit->vehicleRef($vehicle),
                'kinds' => $kinds,
                ...($costs ? [] : ['note' => 'Fuel costs for this vehicle are not shared with this user.']),
            ],
            'figures' => $figures,
        ];
    }

    /**
     * Fill-ups, volume, spend, price per unit and the economy of the tanks
     * measured in them (only those of $grade, when given).
     *
     * @param list<FillEconomy> $fills
     * @return array{array<string, mixed>, list<string>} the figures, and their key display strings
     */
    private function totals(array $fills, bool $electric, bool $costs, string $currency, ?FuelGrade $grade = null): array
    {
        $volume = '0';
        $spend = '0';
        $distance = '0';
        $measured = '0';
        foreach ($fills as $fill) {
            $volume = Decimal::add($volume, $fill->entry->data->volume);
            $spend = Decimal::add($spend, $fill->entry->data->totalCost);
            $segment = $fill->status === EconomyStatus::Measured ? $fill->segment : null;
            if ($segment !== null && ($grade === null || $segment->grade === $grade)) {
                $distance = Decimal::add($distance, $segment->distanceKm);
                $measured = Decimal::add($measured, $segment->volume);
            }
        }
        $hasEconomy = Decimal::compare($distance, '0') > 0 && Decimal::compare($measured, '0') > 0;
        $price = Decimal::compare($volume, '0') > 0 ? Decimal::divide($spend, $volume, 6) : null;
        $volumeShown = $this->kit->format->quantity($volume, $electric);
        $economyShown = $hasEconomy ? $this->kit->format->economy($distance, $measured, $electric) : null;
        $spendShown = $costs ? $this->kit->format->money(Money::of($spend, $currency)) : null;
        $shown = array_values(array_filter(
            [$volumeShown, $economyShown, $spendShown],
            static fn (?string $s): bool => $s !== null && $s !== '',
        ));

        return [[
            'fill_ups' => count($fills),
            'volume' => [
                $electric ? 'kwh' : 'litres' => $volume,
                'display' => $volumeShown,
            ],
            ...($costs ? [
                'spend' => $this->kit->money(Money::of($spend, $currency)),
                'average_price_per_unit' => $price === null ? null : [
                    'amount' => Decimal::round($price, 3),
                    'display' => $this->kit->format->unitPrice($price, $currency, $electric),
                ],
            ] : []),
            'economy' => $hasEconomy ? [
                'distance_km' => $distance,
                'volume' => $measured,
                'display' => $economyShown,
            ] : null,
        ], $shown];
    }

    /**
     * @param list<FillEconomy> $fills
     * @return array<string, list<FillEconomy>> grade value ('' unrecorded) → fills
     */
    private function byGrade(array $fills): array
    {
        $groups = [];
        foreach ($fills as $fill) {
            $groups[$fill->entry->data->grade->value ?? ''][] = $fill;
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private function verdict(GradeVerdict $verdict): array
    {
        $percent = static fn (?PercentDifference $d): ?array => $d === null
            ? null
            : ['direction' => $d->direction, 'percent' => $d->percent];

        return [
            'grade' => $verdict->grade->value,
            'grade_label' => $this->kit->t($verdict->grade->labelKey()),
            'compared_with' => $verdict->reference->value,
            'compared_with_label' => $this->kit->t($verdict->reference->labelKey()),
            'status' => $verdict->status->value,
            'cost_per_distance' => $percent($verdict->cost()),
            'price' => $percent($verdict->price()),
            'fuel_used' => $percent($verdict->fuelUsed()),
            'note' => 'Over the whole fuel history, not only the period.',
        ];
    }

    /**
     * @return list<string>
     */
    private static function grades(): array
    {
        return array_map(static fn (FuelGrade $g): string => $g->value, FuelGrade::cases());
    }
}
