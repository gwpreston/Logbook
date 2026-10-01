<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Incident\ClaimsFilter;
use Logbook\Service\Incident\ClaimsHistory;
use Logbook\Service\Incident\ClaimsRow;

/**
 * `incidents(vehicles?, years?, from?, to?, claims_only?)` (spec.md §7.26,
 * §7.29): the claims history's rows, archived and sold vehicles included,
 * as the user may see them, never the other party. Answers "Have I had any
 * claims in the last five years?" with the page's own figures.
 */
final readonly class Incidents implements AskTool
{
    public function __construct(
        private ToolKit $kit,
        private ClaimsHistory $history,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'incidents';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'Incidents and insurance claims on the user\'s vehicles, sold and archived ones included, newest first: '
            . 'date, vehicle, type, fault, driver, claim status, insurer, claim number, payout and no-claims effect. '
            . 'Defaults to the last 5 years, which is what insurers usually ask about.',
            [
                'type' => 'object',
                'properties' => [
                    'vehicles' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Vehicle ids; all when left out.',
                    ],
                    'years' => ['type' => 'integer', 'enum' => ClaimsFilter::YEARS],
                    'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD, instead of years.'],
                    'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                    'claims_only' => ['type' => 'boolean', 'description' => 'Only incidents the insurer was told about.'],
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->features->isEnabled(Feature::Incidents);
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        [$vehicles, $named] = $this->kit->vehicles($user, $arguments);
        $ids = array_map(static fn (Vehicle $v): int => $v->id, $vehicles);
        $filter = new ClaimsFilter(
            years: $arguments->int('years') ?? ClaimsFilter::DEFAULT_YEARS,
            from: $arguments->date('from'),
            until: $arguments->date('to'),
            claimsOnly: ($arguments->values['claims_only'] ?? false) === true,
        );
        $report = $this->history->report($user, $filter);
        $rows = array_values(array_filter(
            $report->rows,
            static fn (ClaimsRow $row): bool => in_array($row->vehicle->id, $ids, true),
        ));

        $items = array_map(fn (ClaimsRow $row): array => $this->row($row), $rows);
        $claims = count(array_filter(
            $rows,
            static fn (ClaimsRow $row): bool => $row->incident->claimStatus?->isClaim() ?? false,
        ));
        $period = [
            'from' => $report->from?->format('Y-m-d'),
            'to' => $report->until->format('Y-m-d'),
            'label' => $filter->isRange()
                ? $this->kit->t('report.period', [
                    'from' => $report->from === null ? '' : $this->kit->format->date($report->from),
                    'to' => $this->kit->format->date($report->until),
                ])
                : $this->kit->t('incident.history.last_years', ['count' => $filter->years]),
        ];

        return new ToolResult(
            [
                'period' => $period,
                'incidents' => count($rows),
                'claims' => $claims,
                'rows' => $items,
                'note' => 'A row with details false is on a shared vehicle whose claim details the user may not see.',
            ],
            $this->kit->source([$this->kit->t('incident.history.title'), $period['label']]),
            [(string) count($rows), (string) $claims],
            $this->kit->link('/incidents/history', $filter->toQuery()),
            $named ? $ids : array_values(array_unique(array_map(static fn (ClaimsRow $row): int => $row->vehicle->id, $rows))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ClaimsRow $row): array
    {
        $view = $row->incident;

        return [
            'date' => $view->occurredOn->format('Y-m-d'),
            'vehicle' => $this->kit->vehicleRef($row->vehicle),
            'sold_or_archived' => $row->vehicle->isArchived(),
            'type' => $this->kit->t($view->type->labelKey()),
            'write_off' => $view->writeOff->isWrittenOff() ? $this->kit->t($view->writeOff->labelKey()) : null,
            'details' => $view->details,
            'fault' => $view->fault === null ? null : $this->kit->t($view->fault->labelKey()),
            'driver' => $row->driver,
            'claim_status' => $view->claimStatus === null ? null : $this->kit->t($view->claimStatus->labelKey()),
            'insurer' => $view->insurer,
            'claim_number' => $view->claimNumber,
            'payout' => $view->payout === null ? null : $this->kit->format->money($view->payout, $row->currency),
            'no_claims_affected' => $view->ncdAffected?->value,
        ];
    }
}
