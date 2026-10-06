<?php

declare(strict_types=1);

namespace Logbook\Action;

use Logbook\Action\Dashboard\DashboardCharts;
use Logbook\Service\Ai\Draft\DraftCards;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Service\Attention\AttentionWording;
use Logbook\Service\Dashboard\DashboardService;
use Logbook\Service\Dashboard\ExpensePeriod;
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Service\Reminder\ReminderWording;
use Logbook\Service\Report\TrueCostRange;
use Logbook\Service\Report\TrueCostWording;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET / — the dashboard (spec.md §7.8): the owner's widgets in their saved
 * order. `?vehicle={id}` narrows every widget to one active vehicle and pins
 * its card (an unknown or archived id shows the fleet). `?customise=1`
 * shows the move / hide controls (plain forms, so the layout can be
 * arranged without JS), always over the whole fleet. Drafts an MCP
 * client left are listed above the widgets (spec.md §7.28).
 */
final readonly class HomeAction
{
    public function __construct(
        private View $view,
        private DashboardService $dashboards,
        private DashboardCharts $charts,
        private ReminderWording $wording,
        private ClockInterface $clock,
        private ForecastWording $forecastWording,
        private AttentionWording $attentionWording,
        private DraftStore $drafts,
        private DraftCards $draftCards,
        private TrueCostWording $trueCostWording,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams();
        $customise = ($query['customise'] ?? null) === '1';
        $vehicle = $query['vehicle'] ?? null;
        $vehicleId = !$customise && is_string($vehicle) && ctype_digit($vehicle) ? (int) $vehicle : null;
        // The true cost and expense breakdown widgets' periods (spec.md §7.35, §7.8): links, not saved settings.
        $dashboard = $this->dashboards->build(
            $user,
            $vehicleId,
            TrueCostRange::chosen($query['true_cost'] ?? null),
            ExpensePeriod::chosen($query['expenses'] ?? null),
        );

        return $this->view->render($request, $response, 'home.twig', [
            'dashboard' => $dashboard,
            'customise' => $customise,
            'review_drafts' => array_values($this->draftCards->cards($user, $this->drafts->toReview($user))),
            'today' => LocalTime::today($this->clock, $user->preferences->timeZone()),
            'wording' => $this->wording,
            'forecast_wording' => $this->forecastWording,
            'attention_wording' => $this->attentionWording,
            'efficiency_chart' => $this->charts->efficiency($dashboard->efficiency),
            'mileage_chart' => $this->charts->mileage($dashboard->mileage),
            'monthly_spend_charts' => $this->charts->monthlySpend($dashboard->monthlySpend),
            'expense_periods' => ExpensePeriod::cases(),
            'true_cost_wording' => $this->trueCostWording,
        ]);
    }
}
