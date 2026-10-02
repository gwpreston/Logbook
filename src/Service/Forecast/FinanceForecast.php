<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use Logbook\Support\Money\Money;

/**
 * What a finance item of *Coming up* carries (spec.md §7.18, §7.32 *Coming
 * up*): the payments line ("Finance payments, 12 × £312.40", its amount
 * spread over the months the payments fall in) or the final payment.
 */
final readonly class FinanceForecast
{
    /**
     * @param array<int, Money> $months the payments' amounts by horizon month index (the payments line)
     */
    public function __construct(
        public int $agreementId,
        public bool $final,
        /** How many payments the line counts (1 for the final payment). */
        public int $count,
        /** Each payment when they are all the same; null when they differ. */
        public ?Money $each,
        public array $months,
        /** Below `Manage`: no link and no lender (#128). */
        public bool $plain,
    ) {
    }
}
