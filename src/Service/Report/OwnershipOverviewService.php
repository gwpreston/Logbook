<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\FinanceService;

/**
 * The Cost of ownership page's screen (spec.md §7.7 *Cost of ownership
 * page*): a card per vehicle whose costs the user may see, grouped by
 * currency as the ownership report is, each group with its summary.
 */
final readonly class OwnershipOverviewService
{
    public function __construct(
        private TrueCostService $trueCosts,
        private FinanceService $finance,
    ) {
    }

    /**
     * @return list<OwnershipSummary> the currency with most vehicles first
     */
    public function overview(User $user, ReportFilter $filter, DateTimeImmutable $today): array
    {
        /** @var array<string, list<OwnershipCard>> $byCurrency */
        $byCurrency = [];
        foreach ($this->trueCosts->fleet($user, $filter, $today) as $vtc) {
            $vehicle = $vtc->vehicle;
            $agreement = $this->finance->canSee($user, $vehicle) ? $this->finance->active($vehicle) : null;
            $byCurrency[$vtc->currency][] = new OwnershipCard($vtc, $agreement?->data->type);
        }
        uksort($byCurrency, static fn (string $a, string $b): int
            => (count($byCurrency[$b]) <=> count($byCurrency[$a])) ?: strcmp($a, $b));

        $summaries = [];
        foreach ($byCurrency as $currency => $cards) {
            $summaries[] = OwnershipSummary::of($currency, $cards);
        }

        return $summaries;
    }

    /**
     * One vehicle's card, for its *Cost of ownership* tab (spec.md §7.1);
     * null when the user may not see its costs or it has no ownership
     * period.
     */
    public function forVehicle(User $user, Vehicle $vehicle, DateTimeImmutable $today): ?OwnershipCard
    {
        $vtc = $this->trueCosts->forVehicle($user, $vehicle, $today);
        if ($vtc === null) {
            return null;
        }
        $agreement = $this->finance->canSee($user, $vehicle) ? $this->finance->active($vehicle) : null;

        return new OwnershipCard($vtc, $agreement?->data->type);
    }
}
