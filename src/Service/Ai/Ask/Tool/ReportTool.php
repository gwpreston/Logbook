<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskPeriod;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportService;

/**
 * What `costs` and `cost_per_distance` share: Reports (spec.md §7.7) over
 * the vehicles asked for, counting only those whose costs the user may
 * see; the others are named as not shown, never totalled.
 */
abstract readonly class ReportTool
{
    public function __construct(
        protected ToolKit $kit,
        protected ReportService $reports,
        private FeatureToggles $features,
    ) {
    }

    public function isAvailable(User $user): bool
    {
        return $this->features->isEnabled(Feature::Reports);
    }

    /**
     * @return array{Report, AskPeriod, list<Vehicle>, list<Vehicle>, bool} the report, its period, the
     *         vehicles counted, those left out (no ViewCosts) and whether vehicles were named
     */
    protected function report(User $user, ToolArguments $arguments, ?CostGroup $group = null): array
    {
        [$vehicles, $named] = $this->kit->vehicles($user, $arguments);
        $period = $this->kit->period($user, $arguments);
        $counted = array_values(array_filter($vehicles, fn (Vehicle $v): bool => $this->kit->canSeeCosts($user, $v)));
        $hidden = array_values(array_filter($vehicles, fn (Vehicle $v): bool => !$this->kit->canSeeCosts($user, $v)));
        $one = $named && count($counted) === 1 ? $counted[0]->id : null;
        $filter = new ReportFilter($period->reportPeriod(), $one, true, $group);

        return [$this->reports->forVehicles($user, $filter, $counted), $period, $counted, $hidden, $named];
    }

    /**
     * Reports with the same filters. Several named vehicles can't be one
     * Reports page, so they link to the fleet and the source names them.
     *
     * @param list<Vehicle> $counted
     */
    protected function reportLink(AskPeriod $period, array $counted, bool $named, ?CostGroup $group): string
    {
        $query = $period->reportQuery() + ['include_archived' => '1'];
        if ($named && count($counted) === 1) {
            $query['vehicle'] = (string) $counted[0]->id;
        }
        if ($group !== null) {
            $query['group'] = $group->value;
        }

        return $this->kit->link('/reports', $query);
    }

    /**
     * @param list<Vehicle> $hidden
     * @return array<string, mixed>
     */
    protected function hiddenNote(array $hidden): array
    {
        return $hidden === [] ? [] : [
            'costs_not_shared' => array_map(static fn (Vehicle $v): string => $v->name(), $hidden),
            'note' => 'Costs for these vehicles are not shared with this user; they are not counted.',
        ];
    }
}
