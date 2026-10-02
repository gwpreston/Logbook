<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use Logbook\Service\Finance\ScheduledPayment;

/**
 * An active agreement's payments still due, as *Coming up* reads them
 * (spec.md §7.32 *Coming up*), judged by the vehicle owner's today.
 */
final readonly class FinanceDue
{
    /**
     * @param list<ScheduledPayment> $due payments still due (not missed), in date order
     */
    public function __construct(
        public int $agreementId,
        public array $due,
        /** Below `Manage`: plain lines (#128). */
        public bool $plain,
    ) {
    }
}
