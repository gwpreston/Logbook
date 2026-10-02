<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use DateTimeImmutable;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Service\Finance\MileagePosition;

/**
 * What a finance item of *Needs attention* is about (spec.md §7.24 item 11,
 * §7.32 *Needs attention*): a missed payment's due date, or the mileage
 * heading over the allowance.
 */
final readonly class FinanceFinding
{
    public function __construct(
        public FinanceAgreement $agreement,
        public string $currency,
        /** The missed payment's due date (FinanceMissed). */
        public ?DateTimeImmutable $dueOn = null,
        /** The projection (FinanceMileage). */
        public ?MileagePosition $mileage = null,
    ) {
    }
}
