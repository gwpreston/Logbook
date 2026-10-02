<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolError;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Finance\AgreementView;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Money\Money;

/**
 * `finance(vehicle)` (spec.md §7.26, §7.32 *API* and *Ask*): the active
 * agreement's figures with their labels, else the latest ended one's, as
 * its page shows them. Estimates are marked as such; never the agreement
 * number; figures, never advice. Someone who may not see the vehicle's
 * finance gets the same answer as for a vehicle they cannot see.
 */
final readonly class Finance implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private FinanceService $finance,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'finance';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'A vehicle\'s finance or lease agreement (hire purchase, PCP, personal loan or lease): payments remaining, '
            . 'what remains to pay (exact), the next payment, the end date, the settlement figure (the lender\'s quote, '
            . 'or an estimate), cost of credit, the half-paid point, equity, and the mileage against the allowance with '
            . 'any projected excess charge. Figures, never advice.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicle' => ['type' => 'integer', 'description' => 'Vehicle id.'],
                ],
                'required' => ['vehicle'],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->features->isEnabled(Feature::Finance);
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $vehicle = $this->kit->vehicle($user, $arguments);
        if (!$this->finance->canSee($user, $vehicle)) {
            throw new ToolError(ToolKit::NOT_FOUND);
        }
        $view = $this->finance->latestView($user, $vehicle);
        $source = $this->kit->source([$this->kit->t('ask.tool.finance'), $vehicle->name()]);
        if ($view === null) {
            return new ToolResult(
                ['vehicle' => $this->kit->vehicleRef($vehicle), 'agreement' => null],
                $source,
                [],
                '/vehicles/' . $vehicle->id,
                [$vehicle->id],
            );
        }

        $figures = $view->figures;
        $shown = [$this->kit->format->money($figures->remainingToPay)];
        if ($figures->settlement !== null) {
            $shown[] = $this->kit->format->money($figures->settlement->amount);
        }

        return new ToolResult(
            [
                'vehicle' => $this->kit->vehicleRef($vehicle),
                'agreement' => $this->agreement($view),
                'note' => 'Figures, not financial advice: never recommend settling, handing back, refinancing or ending. '
                    . 'Say which figures are estimates.',
            ],
            $source,
            $shown,
            '/vehicles/' . $vehicle->id . '/finance/' . $view->agreement->id,
            [$vehicle->id],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function agreement(AgreementView $view): array
    {
        $agreement = $view->agreement;
        $data = $agreement->data;
        $figures = $view->figures;
        $schedule = $figures->schedule;
        $money = fn (?Money $amount): ?array => $amount === null ? null : $this->kit->money($amount);
        $date = fn (?\DateTimeImmutable $on): ?string => $on === null ? null : $this->kit->format->date($on);
        $next = $schedule->next();
        $settlement = $figures->settlement;
        $credit = $figures->costOfCredit;
        $half = $figures->halfPaid;
        $mileage = $view->mileage;
        $unit = $mileage?->unit;

        return [
            'type' => $this->kit->t($data->type->labelKey()),
            'lender' => $data->lender,
            'status' => $this->kit->t($agreement->status->labelKey()),
            'started_on' => $date($data->startedOn),
            'ends_on' => $date($figures->endsOn),
            'ended_on' => $date($agreement->endedOn),
            'apr_percent' => $data->type->isCredit() ? $data->apr : null,
            'payments_remaining' => $this->kit->t('finance.payments_remaining', [
                'remaining' => $schedule->remaining(),
                'total' => $schedule->numberOfPayments,
            ]),
            'next_payment' => $next === null ? null : [
                'due_on' => $date($next->dueOn),
                'amount' => $this->kit->money(Money::of($next->amount, $view->currency)),
            ],
            'remaining_to_pay' => ['label' => 'exact, from the agreement'] + $this->kit->money($figures->remainingToPay),
            'optional_final_payment' => $figures->optionalFinal === null ? null : [
                'due_on' => $date($figures->optionalFinal->dueOn),
                'amount' => $this->kit->money(Money::of($figures->optionalFinal->amount, $view->currency)),
            ],
            'total_amount_payable' => $money($figures->totalAmountPayable),
            'settlement' => $settlement === null ? null : [
                'estimate' => $settlement->isEstimate(),
                'label' => $settlement->isEstimate()
                    ? $this->kit->t('finance.settlement_estimate_hint')
                    : $this->kit->t('finance.settlement_quoted', [
                        'amount' => $this->kit->format->money($settlement->amount),
                        'quoted' => $date($settlement->quote?->quotedOn),
                        'until' => $date($settlement->quote?->validUntil),
                    ]),
            ] + $this->kit->money($settlement->amount),
            'cost_of_credit' => $credit === null ? null : [
                'over_the_agreement' => $money($credit->total),
                'so_far' => $money($credit->soFar),
                'so_far_is_estimate' => $credit->soFar !== null && !$credit->exact,
            ],
            'half_paid_point' => $half === null ? null : [
                'reached' => $half->reached,
                'on' => $date($half->on),
                'still_needed' => $money($half->stillNeeded),
                'label' => $this->kit->t('finance.half_paid_hint'),
            ],
            'equity' => $figures->equity === null ? null : [
                'label' => $this->kit->t($figures->equity->isPositive() ? 'finance.equity_positive' : 'finance.equity_negative', [
                    'amount' => $this->kit->format->money($figures->equity->magnitude()),
                ]),
                'estimate' => $settlement?->isEstimate() ?? true,
                'valued_on' => $date($figures->equity->valuedOn),
            ],
            'mileage' => $mileage === null || $unit === null ? null : [
                'allowance' => $this->kit->format->distance($mileage->allowanceKm, 0, $unit),
                'distance_so_far' => $mileage->distanceKm === null
                    ? null
                    : $this->kit->format->distance($mileage->distanceKm, 0, $unit),
                'projected_at_end' => $mileage->projectedKm === null
                    ? null
                    : $this->kit->format->aboutDistance($mileage->projectedKm, $unit),
                'projected_over' => $mileage->isOver(),
                'projected_excess_charge' => $money($mileage->excessCharge),
                'is_estimate' => $mileage->projectedKm !== null && $agreement->status->isActive(),
            ],
        ];
    }
}
