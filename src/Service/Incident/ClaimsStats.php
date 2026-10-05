<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use DateTimeImmutable;
use Logbook\Domain\Incident\Fault;
use Logbook\Support\Money\Money;

/**
 * The claims history's tiles (spec.md §7.29 *Claims history*, Phase 33.3):
 * the claims among the rows in view and how many were at fault, the time
 * since the latest at-fault claim, and what insurers paid and the excess
 * per currency. Only rows whose details the viewer may see count; the
 * amounts only where they may also see the amounts. Pure.
 */
final readonly class ClaimsStats
{
    /**
     * @param list<Money>|null $paid per currency; null when no claim's amounts are visible
     * @param list<Money>|null $excess per currency; null when no claim's amounts are visible
     */
    private function __construct(
        public int $claims,
        public int $atFault,
        /** The latest at-fault claim's date; null when there is none in view. */
        public ?DateTimeImmutable $lastFault,
        /** Whole years from it to today (0: under a year). */
        public ?int $yearsSinceFault,
        public ?array $paid,
        public ?array $excess,
        /** Rows in view whose details are hidden from the viewer. */
        public int $hidden = 0,
    ) {
    }

    /**
     * @param list<ClaimsRow> $rows
     * @param DateTimeImmutable $today the owner's today
     */
    public static function of(array $rows, DateTimeImmutable $today): self
    {
        $claims = 0;
        $atFault = 0;
        $lastFault = null;
        /** @var array<string, Money>|null $paid */
        $paid = null;
        /** @var array<string, Money>|null $excess */
        $excess = null;
        $hidden = 0;
        foreach ($rows as $row) {
            $incident = $row->incident;
            if (!$incident->details) {
                ++$hidden;
                continue;
            }
            if (!($incident->claimStatus?->isClaim() ?? false)) {
                continue;
            }
            ++$claims;
            if ($incident->fault === Fault::AtFault) {
                ++$atFault;
                if ($lastFault === null || $incident->occurredOn > $lastFault) {
                    $lastFault = $incident->occurredOn;
                }
            }
            if ($incident->amounts) {
                $paid = self::add($paid ?? [], $row->currency, $incident->payout);
                $excess = self::add($excess ?? [], $row->currency, $incident->excess);
            }
        }

        return new self(
            $claims,
            $atFault,
            $lastFault,
            $lastFault === null ? null : max(0, $lastFault->diff($today)->y),
            $paid === null ? null : array_values($paid),
            $excess === null ? null : array_values($excess),
            $hidden,
        );
    }

    /**
     * @param array<string, Money> $sums
     * @return array<string, Money>
     */
    private static function add(array $sums, string $currency, ?string $amount): array
    {
        $sum = $sums[$currency] ?? Money::zero($currency);
        $sums[$currency] = $amount === null ? $sum : $sum->add(Money::of($amount, $currency));

        return $sums;
    }
}
