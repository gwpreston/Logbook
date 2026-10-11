<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Property;

use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Incident\IncidentAccess;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Notification\Digest\DigestSummary;
use Logbook\Service\Report\OwnershipService;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\Property\Figures;
use Logbook\Tests\Support\Property\PropertyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Hidden means hidden (Phase 45.1 property, spec §5 *Costs*): a member
 * whose share of a vehicle doesn't include its costs gets no amount for it
 * from any service that works one out for a page: Reports, cost of
 * ownership, true cost, *Coming up*, the digest's *Last month*, finance and
 * an incident's costs. The vehicles they may see costs of still count.
 * Routes, the API, exports and Ask are swept in Phase 45.2.
 */
#[Group('property')]
final class HiddenMeansHiddenPropertyTest extends PropertyTestCase
{
    #[DataProvider('seeds')]
    public function testAMemberWithoutCostsGetsNoAmountForTheVehicle(int $seed): void
    {
        [$app, $plan, $writer] = $this->household($seed);
        $member = $writer->member;
        $fleet = $this->service($app, VehicleService::class)->listFleet($member, true);
        $hidden = [];
        foreach ($plan->vehicles as $vehicle) {
            if (!$vehicle->memberSeesCosts) {
                $hidden[] = $writer->vehicles[$vehicle->key]->id;
            }
        }
        self::assertNotSame([], $hidden, 'every household hides one vehicle');
        $why = $plan->describe();
        $today = Figures::today();
        $isHidden = static fn (int $id): bool => in_array($id, $hidden, true);
        $all = new ReportFilter(ReportPeriod::preset(ReportRange::AllTime, $today), includeArchived: true);

        foreach ($this->service($app, ReportService::class)->build($member, $all)->currencies as $currency) {
            foreach ($currency->vehicles as $line) {
                self::assertFalse($isHidden($line->vehicle->id), "Reports: {$why}");
            }
        }
        foreach ($this->service($app, OwnershipService::class)->report($member, $all, $today)->sections as $section) {
            foreach ($section->rows as $row) {
                self::assertFalse($isHidden($row->vehicle->id), "cost of ownership: {$why}");
            }
        }
        foreach ($this->service($app, TrueCostService::class)->forVehicles($member, $fleet, $today) as $result) {
            self::assertFalse($isHidden($result->vehicle->id), "true cost: {$why}");
        }
        foreach ($this->service($app, TrueCostService::class)->fleet($member, $all, $today) as $result) {
            self::assertFalse($isHidden($result->vehicle->id), "true cost, fleet: {$why}");
        }

        $forecast = $this->service($app, ComingUp::class)->forecast($member, $fleet);
        foreach ($forecast->items() as $item) {
            if ($isHidden($item->vehicle->id)) {
                self::assertNull($item->cost, "Coming up, {$item->title}: {$why}");
            }
        }
        foreach ($forecast->fuel as $fuel) {
            if ($isHidden($fuel->vehicle->id)) {
                self::assertNull($fuel->total(), "Coming up, fuel: {$why}");
            }
        }

        $lastMonth = $this->service($app, DigestSummary::class)->lastMonth($member, $fleet, $today);
        foreach ($lastMonth === null ? [] : $lastMonth->lines as $line) {
            if ($isHidden($line->vehicle->id)) {
                self::assertNull($line->costs, "digest, Last month: {$why}");
            }
        }

        $incidents = $this->service($app, IncidentService::class);
        foreach ($fleet as $vehicle) {
            if (!$isHidden($vehicle->id)) {
                continue;
            }
            self::assertFalse($this->service($app, FinanceService::class)->canSee($member, $vehicle), "finance: {$why}");
            foreach ($incidents->list($vehicle) as $incident) {
                $view = $this->service($app, IncidentAccess::class)
                    ->view($member, $vehicle, $incident, $incidents->costs($member, $vehicle, $incident));
                self::assertFalse($view->amounts, "incident: {$why}");
                self::assertNull($view->payout, "incident payout: {$why}");
                self::assertNull($view->excess, "incident excess: {$why}");
                self::assertNull($view->costs, "incident costs: {$why}");
            }
        }
    }
}
