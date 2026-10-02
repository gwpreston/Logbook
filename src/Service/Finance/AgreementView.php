<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEvent;
use Logbook\Domain\Finance\SettlementQuote;

/**
 * One agreement with everything its page shows (spec.md §7.32 *Agreement
 * page*).
 */
final readonly class AgreementView
{
    /**
     * @param list<PaymentEvent> $events
     * @param list<SettlementQuote> $quotes newest first
     * @param list<FinanceCheck> $checks figures that don't add up
     * @param list<ExpenseEntry> $overlap manual finance expenses in the months it covers
     */
    public function __construct(
        public FinanceAgreement $agreement,
        public FinanceFigures $figures,
        public array $events,
        public array $quotes,
        public array $checks,
        public array $overlap,
        public string $currency,
    ) {
    }
}
