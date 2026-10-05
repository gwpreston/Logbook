<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Domain\Incident\Fault;
use Logbook\Support\Money\Money;

/**
 * The Incidents tab's strip (spec.md §7.29 *Layout*): how many incidents,
 * how many claims and, when every fault is visible, how many at fault, and
 * what insurers paid and the net cost per currency. Read from the views,
 * so it counts only what the viewer may see: a hidden claim is not a
 * claim here, a hidden payout is not paid, and an incident whose payout is
 * hidden adds its linked costs to the net cost, as its card shows. Pure.
 */
final readonly class IncidentStats
{
    /**
     * @param list<Money> $insurerPaid per currency, in the order first seen
     * @param list<Money> $netCost per currency, in the order first seen
     */
    private function __construct(
        public int $incidents,
        /** Claims among the incidents whose details are visible; null when none are. */
        public ?int $claims,
        /** At-fault claims; null unless every incident's details are visible. */
        public ?int $atFault,
        public array $insurerPaid,
        public array $netCost,
        /** Some of the net cost is linked costs only: their payouts are hidden. */
        public bool $payoutsHidden = false,
    ) {
    }

    /**
     * @param list<IncidentView> $incidents
     */
    public static function of(array $incidents): self
    {
        $claims = 0;
        $atFault = 0;
        $visible = 0;
        /** @var array<string, Money> $paid */
        $paid = [];
        /** @var array<string, Money> $net */
        $net = [];
        $payoutsHidden = false;
        foreach ($incidents as $incident) {
            if ($incident->details) {
                ++$visible;
                if ($incident->claimStatus?->isClaim() ?? false) {
                    ++$claims;
                    if ($incident->fault === Fault::AtFault) {
                        ++$atFault;
                    }
                }
            }
            $costs = $incident->costs;
            if ($costs === null) {
                continue;
            }
            if ($incident->showsPayouts()) {
                $paid = self::add($paid, $costs->payouts);
                $net = self::add($net, $costs->net);
            } else {
                $net = self::add($net, $costs->linked);
                $payoutsHidden = true;
            }
        }
        $count = count($incidents);

        return new self(
            $count,
            $visible === 0 && $count > 0 ? null : $claims,
            $visible === $count ? $atFault : null,
            array_values($paid),
            array_values($net),
            $payoutsHidden,
        );
    }

    /**
     * @param array<string, Money> $sums
     * @return array<string, Money>
     */
    private static function add(array $sums, Money $amount): array
    {
        $sums[$amount->currency] = ($sums[$amount->currency] ?? Money::zero($amount->currency))->add($amount);

        return $sums;
    }
}
