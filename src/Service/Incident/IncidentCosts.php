<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Service\Expense\CostItem;
use Logbook\Support\Money\Money;

/**
 * What an incident cost (spec.md §7.29 *Costs*): the ledger lines of the
 * records linked to it, so the figure is the one Reports counts (a linked
 * tyre change adds nothing: its cost is its service record's), less the
 * payout. Pure: no database.
 */
final readonly class IncidentCosts
{
    private function __construct(
        /** The sum of the linked records' ledger lines. */
        public Money $linked,
        /** Money received from the insurer. */
        public Money $payouts,
        /** Linked minus payouts, never below 0 (see receivedMore). */
        public Money $net,
        /** The payout was more than the linked costs. */
        public bool $receivedMore,
        /** How many ledger lines are linked. */
        public int $count,
    ) {
    }

    /**
     * @param list<CostItem> $items the vehicle's ledger lines (any incident)
     * @param string|null $payout the incident's payout (canonical decimal)
     */
    public static function of(int $incidentId, array $items, ?string $payout, string $currency): self
    {
        $linked = Money::zero($currency);
        $count = 0;
        foreach ($items as $item) {
            if ($item->incidentId === $incidentId) {
                $linked = $linked->add($item->amount);
                ++$count;
            }
        }
        $payouts = $payout === null ? Money::zero($currency) : Money::of($payout, $currency);
        $net = $linked->subtract($payouts);
        $receivedMore = $net->isNegative();

        return new self($linked, $payouts, $receivedMore ? Money::zero($currency) : $net, $receivedMore, $count);
    }
}
