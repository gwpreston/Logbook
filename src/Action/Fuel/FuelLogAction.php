<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Vehicle\VehicleService;
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
 * lists only those (spec.md §7.3).
 */
final readonly class FuelLogAction
{
    public function __construct(
        private VehicleService $vehicles,
        private FuelService $fuel,
        private FuelCharts $charts,
        private AttachmentService $attachments,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $history = $this->fuel->history($vehicle);
        $checks = $this->fuel->checks($history);
        $toCheck = ($request->getQueryParams()['check'] ?? '') === '1';
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
            $sections[] = [
                'kind' => $kind,
                'electric' => $kind === EnergyKind::Electric,
                'summary' => $summary,
                'grades' => $grades[$kind->value] ?? null,
                'to_check' => $checks->flaggedCount($kind),
                'economy_chart' => $this->charts->economy($history, $kind, $user->preferences),
                'price_chart' => $this->charts->price($history, $kind, $user->preferences, $currency),
            ];
        }

        return $this->view->render($request, $response, 'fuel/index.twig', [
            'vehicle' => $vehicle,
            'currency' => $currency,
            'history' => $history,
            'checks' => $checks,
            'to_check' => $toCheck,
            'sections' => $sections,
            'rows' => $pagination->slice($rows),
            'pagination' => $pagination,
            'consumption_units' => ConsumptionUnit::cases(),
            'attachment_counts' => $this->attachments->counts($vehicle),
        ]);
    }
}
