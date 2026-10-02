<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Brick\Math\BigDecimal;
use Logbook\Domain\Finance\AgreementData;

/**
 * Whether an agreement's figures add up (spec.md §7.32 *Consistency check*):
 * a warning to check the paperwork, never a reason to refuse it. A
 * difference of more than 1.00 warns; rounding on the paperwork doesn't.
 */
final readonly class FinanceCheck
{
    public const string TOLERANCE = '1.00';

    private function __construct(
        /** `total` (the total amount payable) or `credit` (the amount of credit). */
        public string $kind,
        /** What the other figures add up to, pennies. */
        public string $computed,
        /** What the agreement says. */
        public string $stated,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function of(AgreementData $data): array
    {
        $checks = [];
        if ($data->totalAmountPayable !== null) {
            $checks[] = self::compare('total', AgreementFigures::derivedTotal($data), $data->totalAmountPayable);
        }
        if ($data->type->hasCashPrice() && $data->cashPrice !== null && $data->amountOfCredit !== null) {
            $credit = BigDecimal::of($data->cashPrice)->minus($data->customerDeposit)->minus($data->dealerContribution);
            $checks[] = self::compare('credit', $credit, $data->amountOfCredit);
        }

        return array_values(array_filter($checks));
    }

    private static function compare(string $kind, BigDecimal $computed, string $stated): ?self
    {
        $difference = $computed->minus($stated)->abs();
        if (!$difference->isGreaterThan(self::TOLERANCE)) {
            return null;
        }

        return new self($kind, FinanceMath::money($computed), FinanceMath::money(BigDecimal::of($stated)));
    }
}
