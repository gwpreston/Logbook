<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Station\StationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\View\Pagination;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/fuel — the fuel log: fill-ups with per-fill economy and
 * cost, summaries, and economy / price trend charts. Liquid fuel and
 * electricity are summarised separately; the vehicle's own kind comes first.
 * Economy checks flag the rows whose economy is far from usual; `?check=1`
 * lists only those. Fuel insights (grade verdict, cost per distance trend
 * with `?trend=cost`, economy by month) are derived from the same history
 * (spec.md §7.3).
 */
final readonly class FuelLogAction
{
    public function __construct(
        private VehicleService $vehicles,
        private FuelService $fuel,
        private FuelCharts $charts,
        private AttachmentService $attachments,
        private View $view,
        private StationService $stations,
        private VehicleAccess $access,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $history = $this->fuel->history($vehicle);
        $checks = $this->fuel->checks($history);
        $toCheck = ($request->getQueryParams()['check'] ?? '') === '1';
        $trend = TrendMode::fromQuery($request->getQueryParams());
        $timeZone = $user->preferences->timeZone();
        $rows = $history->newestFirst();
        if ($toCheck) {
            $rows = array_values(array_filter($rows, static fn (FillEconomy $f): bool => $checks->isFlagged($f->entry->id)));
        }
        $pagination = Pagination::fromQuery($request->getQueryParams(), count($rows));

        $grades = $this->fuel->gradeBreakdowns($history);
        $primary = Fuel::defaultFor($vehicle->data->fuelType)->kind();
        $kinds = [$primary, ...array_filter(EnergyKind::cases(), static fn (EnergyKind $k): bool => $k !== $primary)];
        $sections = [];
        foreach ($kinds as $kind) {
            $summary = $history->summary($kind);
            if ($summary === null) {
                continue;
            }
            $breakdown = $grades[$kind->value] ?? null;
            $costs = $this->fuel->segmentCosts($history, $kind);
            $monthly = $this->fuel->monthlyEconomy($history, $kind, $timeZone);
            $sections[] = [
                'kind' => $kind,
                'electric' => $kind === EnergyKind::Electric,
                'summary' => $summary,
                'grades' => $breakdown,
                'verdicts' => $breakdown === null ? [] : $this->fuel->gradeVerdicts($history, $breakdown, $timeZone),
                'to_check' => $checks->flaggedCount($kind),
                'economy_chart' => $this->charts->economy($history, $kind, $user->preferences),
                'costs' => $costs,
                'cost_chart' => $this->charts->cost($costs, $kind, $user->preferences, $currency),
                'price_chart' => $this->charts->price($history, $kind, $user->preferences, $currency),
                'monthly' => $monthly,
                'monthly_chart' => $this->charts->monthly($monthly, $user->preferences),
            ];
        }

        return $this->view->render($request, $response, 'fuel/index.twig', [
            'vehicle' => $vehicle,
            'currency' => $currency,
            'history' => $history,
            'checks' => $checks,
            'to_check' => $toCheck,
            'trend' => $trend,
            'trend_modes' => TrendMode::cases(),
            'sections' => $sections,
            'rows' => $pagination->slice($rows),
            'pagination' => $pagination,
            'consumption_units' => ConsumptionUnit::cases(),
            'attachment_counts' => $this->attachments->counts($vehicle),
        ] + $this->byStation($user, $vehicle));
    }

    /**
     * The *By station* card (spec.md §7.33): the top five stations by spend
     * in the last 12 months, with the average paid for the vehicle's main
     * grade there (the grade it bought most of across them). Needs ViewCosts.
     *
     * @return array<string, mixed>
     */
    private function byStation(User $user, Vehicle $vehicle): array
    {
        if (!$this->stations->enabled() || !$this->access->can($user, VehicleAbility::ViewCosts, $vehicle)) {
            return ['by_station' => []];
        }
        $top = $this->stations->topForVehicle($user, $vehicle);
        $volumes = [];
        foreach ($top as $row) {
            foreach ($row['summary']->grades as $stats) {
                $volumes[$stats->key()] = ($volumes[$stats->key()] ?? 0.0) + (float) $stats->volume;
            }
        }
        arsort($volumes);

        return ['by_station' => $top, 'by_station_grade' => array_key_first($volumes)];
    }
}
